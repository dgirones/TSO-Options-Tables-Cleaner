/**
 * Compiles .po files in this directory to .mo (gettext MO revision 0).
 * Requires Node.js: node languages/compile-mo.cjs
 *
 * @package TSO_Options_Tables_Cleaner
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

/**
 * Unescape PO string literals (subset of gettext escapes).
 *
 * @param {string} s Escaped segment inside quotes.
 * @return {string}
 */
function unescapePo( s ) {
	let out = '';
	for ( let i = 0; i < s.length; i++ ) {
		if ( s[ i ] !== '\\' || i + 1 >= s.length ) {
			out += s[ i ];
			continue;
		}
		const c = s[ ++i ];
		if ( c === 'n' ) {
			out += '\n';
		} else if ( c === 't' ) {
			out += '\t';
		} else if ( c === 'r' ) {
			out += '\r';
		} else if ( c === '"' ) {
			out += '"';
		} else if ( c === '\\' ) {
			out += '\\';
		} else {
			out += '\\' + c;
		}
	}
	return out;
}

/**
 * Parse msgid or msgstr clause starting at lines[lineIndex].
 *
 * @param {string[]} lines PO lines.
 * @param {number} lineIndex Current index.
 * @param {'msgid'|'msgstr'} keyword Clause keyword.
 * @return {[string, number]} Text and next line index.
 */
function parseClause( lines, lineIndex, keyword ) {
	const kw = keyword + ' ';
	const line = lines[ lineIndex ];
	if ( ! line.startsWith( kw ) ) {
		throw new Error( `Expected ${ keyword } at line ${ lineIndex + 1 }: ${ line }` );
	}
	let result = '';
	let col = kw.length;
	const text = line;

	while ( col < text.length ) {
		while ( col < text.length && /\s/.test( text[ col ] ) ) {
			col++;
		}
		if ( col >= text.length ) {
			break;
		}
		if ( text[ col ] !== '"' ) {
			break;
		}
		col++;
		let seg = '';
		while ( col < text.length ) {
			const ch = text[ col ];
			if ( ch === '\\' && col + 1 < text.length ) {
				seg += ch + text[ col + 1 ];
				col += 2;
				continue;
			}
			if ( ch === '"' ) {
				break;
			}
			seg += ch;
			col++;
		}
		if ( col >= text.length || text[ col ] !== '"' ) {
			throw new Error( `Unterminated string in ${ keyword } (line ${ lineIndex + 1 })` );
		}
		col++;
		result += unescapePo( seg );
	}

	lineIndex++;
	while ( lineIndex < lines.length ) {
		const ln = lines[ lineIndex ];
		if ( ! /^[\t ]*"/.test( ln ) ) {
			break;
		}
		const trimmed = ln.trim();
		if ( ! trimmed.startsWith( '"' ) ) {
			break;
		}
		const end = trimmed.lastIndexOf( '"' );
		if ( end <= 0 ) {
			break;
		}
		const inner = trimmed.slice( 1, end );
		result += unescapePo( inner );
		lineIndex++;
	}

	return [ result, lineIndex ];
}

/**
 * Parse a PO file into msgid -> msgstr map (including empty msgid header).
 *
 * @param {string} content File contents.
 * @return {Object<string, string>}
 */
function parsePoFile( content ) {
	const lines = content.split( /\r?\n/ );
	/** @type {Object<string, string>} */
	const messages = {};
	let i = 0;
	while ( i < lines.length ) {
		const raw = lines[ i ];
		if ( /^\s*$/.test( raw ) || /^\s*#/.test( raw ) ) {
			i++;
			continue;
		}
		if ( ! raw.startsWith( 'msgid' ) ) {
			i++;
			continue;
		}
		const [ msgid, afterId ] = parseClause( lines, i, 'msgid' );
		i = afterId;
		while ( i < lines.length && ( /^\s*$/.test( lines[ i ] ) || /^\s*#/.test( lines[ i ] ) ) ) {
			i++;
		}
		if ( i >= lines.length || ! lines[ i ].startsWith( 'msgstr' ) ) {
			continue;
		}
		const [ msgstr, afterStr ] = parseClause( lines, i, 'msgstr' );
		i = afterStr;
		messages[ msgid ] = msgstr;
	}
	return messages;
}

/**
 * Build MO file buffer (little-endian, revision 0).
 *
 * @param {Object<string, string>} messages Map sorted lexicographically by msgid.
 * @return {Buffer}
 */
function buildMoBuffer( messages ) {
	const keys = Object.keys( messages ).sort( ( a, b ) => ( a < b ? -1 : a > b ? 1 : 0 ) );

	let originalsTable = Buffer.alloc( 0 );
	let translationsTable = Buffer.alloc( 0 );
	const oIndex = [];
	const tIndex = [];

	for ( const key of keys ) {
		const orig = key;
		const trans = messages[ key ];
		oIndex.push( {
			len: Buffer.byteLength( orig, 'utf8' ),
			off: originalsTable.length,
		} );
		originalsTable = Buffer.concat( [
			originalsTable,
			Buffer.from( orig, 'utf8' ),
			Buffer.from( [ 0 ] ),
		] );
		tIndex.push( {
			len: Buffer.byteLength( trans, 'utf8' ),
			off: translationsTable.length,
		} );
		translationsTable = Buffer.concat( [
			translationsTable,
			Buffer.from( trans, 'utf8' ),
			Buffer.from( [ 0 ] ),
		] );
	}

	const n = keys.length;
	const oIdxOff = 28;
	const tIdxOff = oIdxOff + n * 8;
	const strOff = tIdxOff + n * 8;
	const transStrOff = strOff + originalsTable.length;

	const header = Buffer.alloc( 28 );
	header.writeUInt32LE( 0x950412de, 0 );
	header.writeUInt32LE( 0, 4 );
	header.writeUInt32LE( n, 8 );
	header.writeUInt32LE( oIdxOff, 12 );
	header.writeUInt32LE( tIdxOff, 16 );
	header.writeUInt32LE( 0, 20 );
	header.writeUInt32LE( strOff, 24 );

	const oIdxBuf = Buffer.alloc( n * 8 );
	const tIdxBuf = Buffer.alloc( n * 8 );
	for ( let j = 0; j < n; j++ ) {
		oIdxBuf.writeUInt32LE( oIndex[ j ].len, j * 8 );
		oIdxBuf.writeUInt32LE( strOff + oIndex[ j ].off, j * 8 + 4 );
		tIdxBuf.writeUInt32LE( tIndex[ j ].len, j * 8 );
		tIdxBuf.writeUInt32LE( transStrOff + tIndex[ j ].off, j * 8 + 4 );
	}

	return Buffer.concat( [ header, oIdxBuf, tIdxBuf, originalsTable, translationsTable ] );
}

const dir = __dirname;
const pairs = [
	[ 'tso-options-tables-cleaner-ca.po', 'tso-options-tables-cleaner-ca.mo' ],
	[ 'tso-options-tables-cleaner-es_ES.po', 'tso-options-tables-cleaner-es_ES.mo' ],
];

for ( const [ poName, moName ] of pairs ) {
	const poPath = path.join( dir, poName );
	const moPath = path.join( dir, moName );
	const messages = parsePoFile( fs.readFileSync( poPath, 'utf8' ) );
	const buf = buildMoBuffer( messages );
	fs.writeFileSync( moPath, buf );
	process.stdout.write(
		`${ moName }: ${ buf.length } bytes, ${ Object.keys( messages ).length } entries\n`
	);
}

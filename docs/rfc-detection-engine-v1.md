# RFC v1.1 — Motor unificat de detecció wp_options

**Producte:** TSO Options & Tables Cleaner (`tso-options-tables-cleaner`)  
**Estat:** Revisió intern (v1.1) — substitueix el draft v1 del 2026-08-26  
**Autor:** TSO / Cursor agent  
**Data revisió:** 2026-08-26  
**Àmbit:** Detecció de propietari per claus `wp_options` (pestanya Options + auditoria + Confirmar/Assignar)

---

## 0. Veredicte de la revisió

El draft v1 **encerta la direcció**: avui hi ha dues arquitectures paral·leles (cascada first-match a `tsootc_detect_plugin()` i scoring parcial a `tso-detection-score.php`) i el camí correcte és **un sol motor de candidats + evidència + puntuació + marge**.

No s’ha d’implementar el draft v1 tal com està escrit. Els errors no són de principi, sinó d’**ordre de migració**, de **generadors incomplets** i de **tres buits arquitectònics** que provocarien regressions (falsos “Sense confirmar”, grups mixtos i sobre-atribució TSO/codescan).

| Draft v1 | Revisió v1.1 |
|----------|----------------|
| Generadors en paral·lel des del dia 1, després un registre `branded_rules` | Primer **embolcallar** els `detect_*` actuals; el registre de regles és **fase final**, no la primera |
| Fase A = G0–G3 + flag apagat (sense efecte visible) | Fase A = **shadow mode** (calcula cascada i resolver, UI segueix cascada) |
| Coherència de grup a la Fase C (després del switch) | **Fase 0 independent**: agrupar per token de propietari, no pel nom de display |
| `custom_map` i `option_key_map` igual de “trusted” | Només `custom_map` és autoritat absoluta; el mapa persistent s’ha de **validar** |
| Codescan `update_option` pes 85 | Sense AST això és v2; v1 manté pes **moderat** (string ≠ update_option) |
| `TSOOTC_DETECTION_SCORE_MARGIN` “ja existeix” | **No existeix**: el marge 10 està hardcodejat a `tsootc_detection_pick_scored_winner()` |
| Flag `define( 'TSOOTC_DETECTION_ENGINE_V2' )` a wp-config | Filter + opció emmagatzemada (no escriure `wp-config.php`) |
| UI unconfirmed: pregunta oberta grup vs badge | El grup `❓ Sense confirmar` **ja existeix**; afegir badge + filtre, no un segon grup |

**Taules extra:** el draft v1 té raó a deixar-les fora. `includes/tso-table-detection.php` ja té collect/score/priority. El motor d’opcions ha de **copiar aquesta forma d’API**, no inventar-ne una de nova.

---

## 1. Resum executiu (problema real)

Avui la detecció té **dues arquitectures paral·leles**:

| Camí | Fitxer(s) | Comportament |
|------|-----------|--------------|
| **Cascada** | `tsootc_detect_plugin()` → `tsootc_detect_plugin_with_history()` | ~15 fases; **el primer match guanya** i fa `return`. |
| **Scoring** | `tso-detection-score.php` | Recull **només 4 fonts** (widget, codescan cache, option_key_map, slug); puntua; tria guanyador amb llindar 35 + marge 10. |

El scoring només s’usa com a **gate al final** (`tsootc_detection_apply_confidence_gate`). En batch ràpid (`fast`) el gate **no degrada** files febles a unconfirmed: només anota `confidence_score`. Això provoca:

- Sobreescriptures post-cascada (codescan cache pot substituir un detect buit; patches ad-hoc eviten `theme_mods_*` → plugin).
- Falsos positius de prefixos curts / paraules del nom (FASE 3 de la cascada: `WooCommerce` → `woo_*`, abreviacions de 5–7 lletres).
- Fallback TSO: qualsevol clau `tso_*` / `tsootc_*` no mapejada acaba a **aquest** plugin (`tsootc_fallback`).
- Recursió perigosa a widgets: `tsootc_detect_plugin( $inner_clean )` reentra la cascada (`widget_mts_*` → altres plugins).
- Grups “mixtos”: la pestanya agrupa per **`detected['name']`** (etiqueta), no per carpeta. `tsootc_group_rekey_and_merge()` fusiona per label. L’auditoria només compara **primera vs última** clau del grup.

**Proposta:** un **motor únic** de candidats + evidència + puntuació. La cascada passa a ser un **registre de generadors**. El grouping passa a ser per **owner token** (el que ja calcula `tsootc_audit_detection_owner_token()`).

---

## 2. Objectius

1. **Un sol punt d’entrada** per a la pestanya Options: `tsootc_detection_resolve_option( $option_name, $inventory, $args )`.
2. **Mai assignar automàticament** quan dos *propietaris diferents* tenen puntuació similar (marge configurable).
3. **Fusionar evidència** del mateix propietari abans de comparar (si no, WooCommerce×2 dispara unconfirmed fals).
4. **Evidència explícita** per candidat (debug, auditoria, UI).
5. **Guards estructurals** (theme vs plugin, hosting/SDK sintètic) com a filtres, no patches post-hoc.
6. **Coherència de grup per token de carpeta**, amb split — no només un badge a auditoria.
7. **Compatibilitat:** `custom_map`, `option_key_map`, historial i codescan continuen; canvia l’ordre de decisió.
8. **Regressió:** cada bug real → fixture a `tso-detection-regression.php`.

---

## 3. No-objectius (v1)

- Refactor de detecció de **taules extra** (el patró ja existeix a `tso-table-detection.php`; RFC separat per unificar-lo).
- ML / embeddings / serveis externs.
- Canvi del model d’emmagatzematge de mapes (DB schema).
- Reescriure codescan complet (AST `update_option` → RFC v2).
- Reescriure tots els `detect_*` a un DSL de regles **abans** de tenir paritat (això era el risc principal del draft v1).

---

## 4. Estat actual (referència)

```
option_name
    │
    ├─► tsootc_detect_plugin()          ← cascada, return early
    │       custom_map
    │       TSO branded (+ fallback a aquest plugin)
    │       Woo / ProfilePress / Jetpack / TML
    │       Freemius / Action Scheduler / hosting / WP Toolkit / legacy WP
    │       OptionTree / known exact map
    │       widget autodetect
    │       theme_mods_<slug>           ← correcte, però tard relatiu a TSO/Woo
    │       option_key_map (persistent)
    │       theme row genèric / The7 / legacy theme / Responsive
    │       external_updates-<slug>
    │       widget_ recursiu (inner → detect_plugin de nou)
    │       bootstrap basename
    │       FASE 1 slug inventory (variants, paraules ≥4)
    │       FASE 2 prefix map + slug hints
    │       FASE 2.5 slug match index
    │       FASE 3 paraules del NOM del plugin + abreviació 5–7   ← alt FP
    │       autodetect_option_prefix
    │       codescan live (si no fast)
    │
    └─► tsootc_detect_plugin_with_history()
            reconcile label
            early return custom_map / option_key_map
            codescan cache (pot omplir buit; no pisa tema)
            history enhance
            codescan live si label-only
            correct_theme / correct_plugin / cross-plugin
            confidence_gate (en fast batch NO degrada a unconfirmed)
```

**Fitxers clau:**

- `includes/tso-core.php` — cascada, agrupació (`group_key` = name), AJAX confirm/assign.
- `includes/tso-detection-score.php` — scoring parcial, `TSOOTC_DETECTION_SCORE_THRESHOLD` (35), marge **hardcodejat 10**.
- `includes/tso-table-detection.php` — model collect/score/priority **ja madur** (copiar forma).
- `includes/tso-audit.php` — `tsootc_audit_detection_owner_token()`, mostra mixta primera/última.
- `includes/tso-code-scan.php` — índex grep / mapping.
- `includes/tso-tracking.php` — historial, `option_key_map` validation.
- `includes/tso-detection-regression.php` — fixtures (ja cobreix theme_mods, TML, Freemius, mapes invàlids).
- `includes/tso-maps.php` — prefix map (inclou `theme_mods_` com a label, però la cascada ja intercepta abans).

**Pestanya Options (batch):** `fast => true` per a cada clau. Per tant qualsevol gate que “només va en slow” **no protegeix** la llista principal.

---

## 5. Arquitectura corregida

### 5.1 Flux

```
option_name + inventory + args{fast|slow}
    │
    ▼
[0] AUTHORITATIVE ── custom_map (intent de l’admin)
    │                 si hi ha fila → return (no scoring)
    ▼
[1] GENERATORS ──► array<Candidate>     (tots els no-autoritatius)
    ▼
[2] FILTERS ─────► descarta invàlids estructuralment
    ▼
[3] MERGE ───────► 1 candidat per owner_token (suma evidència, max score base)
    ▼
[4] SCORER ──────► score final per propietari
    ▼
[5] RESOLVER ────► winner | unconfirmed | null
    │                 winner si score ≥ threshold AND (best - second) ≥ margin
    │                 second = millor propietari DISTINT (després del merge)
    ▼
[6] POST-PROCESS ► reconcile disk, theme label, history label (sense canviar owner)
    ▼
detection row (+ confidence_score, evidence summary, owner_token)
```

Després, a nivell de **pestanya** (batch):

```
[7] GROUP by owner_token (no per detected['name'])
[8] SPLIT si un bucket de label encara barreja tokens
```

### 5.2 Per què el MERGE és obligatori

Sense fusionar, dos generadors que apunten a `woocommerce/woocommerce.php` (prefix map + slug + codescan) competeixen entre ells. El resolver veu `best≈second` i retorna unconfirmed **tot i haver-hi un sol propietari**.

`owner_token` = el que ja fa `tsootc_audit_detection_owner_token()`:

- `theme:{slug}`
- carpeta de plugin (dirname del file, o `folder`)
- `__freemius__` / `__hosting__` / altres sintètics
- `name:{label}` només si no hi ha folder/file (candidat feble)

### 5.3 Tipus de dades

```php
// Candidat (intern, no persistit).
array(
    'row'         => array( /* detection row actual */ ),
    'evidence'    => array(
        array( 'type' => 'custom_map', 'detail' => 'manual assign' ),
        array( 'type' => 'codescan_cache', 'detail' => 'plugins/foo/foo.php' ),
    ),
    'score'       => 78,
    'owner_token' => 'woocommerce',
    'generator'   => 'tsootc_detection_gen_codescan_cache',
);

// Row resolta (sortida pública).
array(
    'name'             => 'WooCommerce',
    'file'             => 'woocommerce/woocommerce.php',
    'folder'           => 'woocommerce',
    'source'           => 'autodetect',
    'owner_token'      => 'woocommerce',
    'confidence_score' => 78,
    'confidence'       => 'high', // high | medium | low | unconfirmed
    'evidence_summary' => 'prefix_match, file_on_disk',
);
```

### 5.4 Generadors (v1 — embolcall, no DSL)

Cada generador retorna **0..N candidats**, mai un `return` definitiu. En v1 els generadors **criden les funcions existents** (`tsootc_detect_jetpack_option()`, etc.). El registre `tso-detection-rules.php` és Fase 4.

| ID | Generador | Origen actual | Pes base (v1) | Notes |
|----|-----------|---------------|---------------|-------|
| G0 | `gen_custom_map` | FASE 0 | trusted / short-circuit | Única autoritat absoluta |
| G1 | `gen_branded_specialists` | TSO, Woo, PPress, Jetpack, TML, Freemius, AS, hosting, WP Toolkit, legacy WP | 55–70 | Embolcalla funcions actuals; **sense** `tsootc_fallback` ceg |
| G2 | `gen_theme_mods` | FASE 0a3 | 95 | Sempre `type=theme` |
| G3 | `gen_option_key_map` | persistent | 70 si vàlid, 0 si no | Després del filtre de validesa |
| G4 | `gen_known_exact_map` | `tsootc_get_known_option_exact_map` | 60 | Draft v1 l’ometia |
| G5 | `gen_widgets` | widget autodetect + mapa | 42 | **Prohibit** reentrar `detect_plugin($inner)` |
| G6 | `gen_theme_heuristics` | theme row, The7, OptionTree, legacy, Responsive | 55 | |
| G7 | `gen_external_updates` | `external_updates-{slug}` | 65 | Draft v1 l’ometia |
| G8 | `gen_bootstrap_basename` | FASE 0e | 50 | |
| G9 | `gen_slug_inventory` | FASE 1 + 2.5 | 35 | Prefix de carpeta ≥ 5; paraules ≥ 4 ja filtrades |
| G10 | `gen_prefix_map` | FASE 2 + slug hints | 30, cap 20 si només label | |
| G11 | `gen_plugin_name_words` | FASE 3 | **cap 18** | Alt FP; mai guanya sol per sobre del threshold 35 |
| G12 | `gen_autodetect_prefix` | `tsootc_autodetect_option_prefix` | 28 | |
| G13 | `gen_history` | tracking | 40 | Només si folder/file encara coherent |
| G14 | `gen_codescan_cache` | code-scan index | 50 | **Rebutjat** per `theme_mods_*` al filtre |
| G15 | `gen_codescan_live` | slow only | 50 | Igual; no inflar a 85 sense AST |

TSO branded: si el prefix és conegut (`tsosk_`, `tsoliin_`, …) → candidat del plugin destí. Si la clau és `tso_*` genèrica **sense hint** → **no** assignar Options Tables Cleaner; unconfirmed o hint.

Jetpack: mantenir llista exacta + prefixes actuals. `jp_`, `jb_`, `wpcom_` són col·lisió; no generalitzar-los a un `prefix => jetpack` del DSL fins a tenir fixtures.

### 5.5 Filtres estructurals (hard rejects)

| Regla | Acció |
|-------|-------|
| `theme_mods_{slug}` | Rebutja candidats `type !== theme` o `folder` sense `theme:` |
| `option_key_map_entry_is_valid` === false | score = 0 (no trusted) |
| Prefix genèric (< 5 chars) sense evidència de disc | score capped ≤ 20 |
| FASE 3 / name-words | score capped ≤ 18 (sota threshold) |
| Synthetic folder (`__hosting__`, `__freemius__`, …) | `on_disk = null`; no mismatch |
| `tsootc_option_key_matches_plugin_folder_evidence` falla | Rebutja match feble |
| Codescan sobre `theme_mods_*` o `widget_` denylist | Rebutja |

`custom_map` **no passa per aquests filtres** (l’admin pot assignar el que vulgui). Es pot mostrar un avís a auditoria, no silenciar l’assignació.

### 5.6 Puntuació

Constants:

```php
TSOOTC_DETECTION_SCORE_THRESHOLD = 35;  // existent
TSOOTC_DETECTION_SCORE_MARGIN    = 10;  // NOU (avui hardcodejat)
```

Pesos v1 (conservadors, alineats amb el scoring actual + bonus de disc):

| Evidència | Pes |
|-----------|-----|
| custom_map | trusted, no score |
| theme_mods_exact | 95 |
| option_key_map (vàlid) | 70 |
| branded specialist (Woo/TML/Jetpack/TSO hint) | 60 |
| codescan_cache / codescan_live | 50 |
| history_index | 40 |
| slug_prefix_match (≥ 5) | 35 |
| prefix_map amb folder al disc | 45 |
| prefix_map_label_only | 15 |
| plugin_name_words / abbr | ≤ 18 |

Bonificacions (ja existents): `+25` file amb `/`, `+15` file exists, `+30` theme_mods slug match, `+10` type=theme.  
Penalitzacions: `row_is_weak` → cap 20; map invalidat → 0.

No pujar codescan a 85 fins que l’índex distingixi `update_option( 'clau' )` d’una simple ocurrència de string (RFC v2).

### 5.7 Resolució

```php
function tsootc_detection_resolve_option( $option_name, $installed_plugins, $args ) {
    $custom = tsootc_detection_gen_custom_map( ... );
    if ( $custom ) {
        return tsootc_detection_finalize_row( $custom, ... );
    }

    $candidates = tsootc_detection_collect_all_candidates( ... );
    $candidates = tsootc_detection_apply_structural_filters( ... );
    $candidates = tsootc_detection_merge_by_owner_token( ... );
    $candidates = tsootc_detection_score_candidates( ... );

    $winner = tsootc_detection_pick_scored_winner_from( $candidates );
    if ( $winner ) {
        return tsootc_detection_finalize_row( $winner, ... );
    }

    return tsootc_detection_build_unconfirmed_row( $option_name, $best_hint );
}
```

Copiar forma de `tsootc_table_detection_source_priority()` per desfer empats de score **del mateix token** (ja fusionats) i per ordenar evidència a la UI.

### 5.8 Coherència de grup (independent del motor)

Avui:

```
group_key = detected['name']   // "WooCommerce", "Tema: the7", "❓ tso_*"
         → group_rekey_and_merge() fusiona per label/folder inconsistent
         → audit compara items[0] vs items[last]
```

Correcte:

1. Cada ítem porta `owner_token` a la fila de detecció.
2. `group_key` intern = token (`woocommerce`, `theme:the7`, `__freemius__`, `__unconfirmed__`).
3. Label de display = `tsootc_resolve_plugin_label_for_folder()` / `tsootc_format_theme_group_label()` (només UI).
4. Si després d’això un grup de display encara té ≥ 2 tokens amb massa ≥ 2 claus cadascun → **split** (no badge i prou).
5. L’auditoria reutilitza el mateix token; deixa de fer spot-check primera/última com a única prova.

Els percentatges 80%/60% del draft v1 són innecessaris si l’agrupació ja és per token. Es poden usar només com a senyal de “outlier” (1 clau dins d’un grup gran amb token diferent per error de label històric) abans del split.

---

## 6. Canvis d’API (compatibilitat)

| Actual | Nou (v1) | Transició |
|--------|----------|-----------|
| `tsootc_detect_plugin()` | Wrappa `resolve_option()` retornant només `row` | Shim mentre el flag és ON; cascada mentre OFF |
| `tsootc_detect_plugin_with_history()` | `resolve_option()` (history és G13 + post-process) | Mateix contracte públic |
| `tsootc_detection_collect_scored_candidates()` | `collect_all_candidates()` + merge | Ampliar fonts; no trencar callers AJAX |
| `tsootc_detection_apply_confidence_gate()` | Lògica dins resolver | En fast batch **també** ha de poder tornar unconfirmed |

**Feature flag** (no `wp-config.php`):

```php
function tsootc_detection_engine_v2_enabled() {
    if ( defined( 'TSOOTC_DETECTION_ENGINE_V2' ) ) {
        return (bool) TSOOTC_DETECTION_ENGINE_V2; // override de staging, opcional
    }
    return (bool) apply_filters(
        'tsootc_detection_engine_v2_enabled',
        (bool) tsootc_get_stored_option_by_id( /* opt-in admin, default false */ )
    );
}
```

Modes:

- `off` — cascada actual (defecte v1.1 fins a Fase 3).
- `shadow` — cascada a la UI; resolver en memòria; log/admin debug del diff.
- `on` — resolver a la UI.

---

## 7. UI / UX

| Estat | Com es mostra |
|-------|----------------|
| `confidence: high` | Grup normal (token sòlid) |
| `confidence: medium` | Icona `?` + tooltip evidència **dins del grup del plugin** |
| `confidence: unconfirmed` | Grup existent `❓ Sense confirmar` (no crear-ne un de nou) |
| grup mixt residual | Split; si no es pot, avís al header (no només auditoria) |
| Confirmar | Desa a `option_key_map` **només la clau exacta** (no promoure prefix) |
| Assignar | Desa a `custom_map` + autoritat |

Auditoria: columna **Evidència** (resum) a més de “Mètode”. Reutilitzar `owner_token` per mismatch.

---

## 8. Rendiment

| Mode | Generadors actius |
|------|-------------------|
| `fast` (llista Options) | G0–G14. **No** G15. |
| `slow` (Confirmar, auditoria on-demand, primera indexació) | Tots + G15 |

- Cache per clau: `{option}|{fast|slow}` (`$GLOBALS['tsootc_opts_detect_cache']`).
- Invalidar: bump mapes, schema, codescan index, plugin install/delete (hooks existents).
- Short-circuit només G0 (`custom_map`). La resta es recull; el cost és PHP in-memory, no I/O, excepte G15.
- Shadow mode: doble detecció; activar-lo només amb filter/opció, no a cada admin anònim.

La llista Options ja és `fast`. El resolver v2 **ha de degradar a unconfirmed en fast**; si no, el bug actual del gate es copia.

---

## 9. Pla de migració (fases correctes)

No començar pel DSL de regles ni per “G0–G3 amb flag apagat”. Ordre: **identitat de grup → shadow amb embolcalls → paritat → switch → neteja**.

### Fase 0 — Identitat de grup (independent, valor immediat)

Objectiu: la pestanya Options i l’auditoria parlen el mateix idioma (`owner_token`).

- Extreure/reutilitzar `tsootc_audit_detection_owner_token()` com a API de grouping.
- `group_key` intern = token; label de display separat.
- Split de grups amb 2+ tokens; deixar de fusionar per nom traduït.
- Auditoria: mismatch per token, no només sample[0] vs sample[last].
- Fixtures: grup TSO propi vs altre plugin TSO; `theme_mods_*` no es barreja amb Theme My Login pel nom.
- **No cal el motor v2.** Treballa sobre la cascada actual.

DoD: Options tab + auditoria sense “mostra mixta” en grups coneguts (Woo, TSO, theme_mods, Freemius). `phpcs-check` + `prefix-audit` verds.

### Fase 1 — Infraestructura shadow (sense canvi de UI)

Objectiu: el resolver existeix i es pot comparar, la UI segueix cascada.

- Fitxers nous: `tso-detection-engine.php` (resolve, filter, merge, finalize), `tso-detection-generators.php` (embolcalls G0–G15 sobre funcions actuals).
- **No** crear encara `tso-detection-rules.php`.
- Flag: `off` / `shadow` / `on` via filter + opció; defecte `off`.
- Definir `TSOOTC_DETECTION_SCORE_MARGIN`.
- Ampliar `collect_scored_candidates` a totes les fonts (o paral·lel al resolver).
- Mode shadow: per clau, `cascade_row` vs `resolver_row`; persistir diffs només si debug (transient/admin, no `debug.log` de WP).
- Fixtures nous mínims:

| ID | option | Assert |
|----|--------|--------|
| `theme_mods_tso_theme` | `theme_mods_tso-theme` | `type=theme`, `folder=theme:tso-theme`, forbidden plugin file |
| `tso_plugin_history_self` | `tso_options_tables_cleaner_*` | token = `tso-options-tables-cleaner`, no altres TSO |
| `tso_generic_no_fallback` | `tso_unknown_widget_setting` | **no** `tsootc_fallback` a aquest plugin |
| `softaculous_hosting` | `softaculous_*` | synthetic, `on_disk=null` |
| `merge_same_owner` | (unit) dos candidats Woo | un sol owner_token, no unconfirmed |

DoD: flag `off` ≡ comportament actual (regressió 0). Shadow no canvia HTML.

### Fase 2 — Paritat i staging

Objectiu: el resolver cobreix cada branca de la cascada, amb menys FP.

- Completar G11 (name-words) amb cap 18; G5 widgets sense recursió.
- Traure `tsootc_fallback` del camí v2.
- Filtres theme_mods / codescan denylist / prefix curt.
- Fast batch: unconfirmed de veritat (canvi visible **només** amb flag `on`).
- Activar `shadow` o `on` a staging; comparar recompte d’auditoria mismatch + diffs shadow.
- Ajustar threshold/margin **només** amb evidència de staging (pregunta oberta 1 del draft).

DoD: shadow diff = 0 en fixtures; staging amb menys mismatches, zero pèrdues de mapes manuals.

### Fase 3 — Switch per defecte + UI

Objectiu: v2 és el camí de producció.

- Defecte `on` (filter encara pot forçar `off`).
- `tsootc_detect_plugin()` / `with_history()` → shim del resolver.
- Badge medium + filtre “Només dubtoses”.
- Confirmar = clau exacta; Assignar = custom_map. Sense promoció automàtica de prefix.
- Documentar a readme/changelog el canvi de detecció (no servei extern).

DoD: smoke Options + una acció Confirmar + una Assignar; regressió PHP; phpcs + prefix-audit.

### Fase 4 — Neteja i registre de regles

Objectiu: esborrar la cascada, no abans.

- `tso-detection-rules.php` per especialistes estables (Woo, TML, Freemius, hosting…).
- Reduir `tsootc_detect_plugin()` a shim.
- RFC v2: codescan AST (`update_option` exacte → pes alt); adaptador taules extra sobre el mateix engine.

---

## 10. Proves

1. **`tso-detection-regression.php`** — obligatori al DoD quan `TSOOTC_WP_LOAD` definit.
2. Cada generador nou o regla branded = 1 fixture mínim.
3. **Smoke manual:** activar plugin → Options → auditoria 0 conflictes esperats per Woo / TSO / theme_mods / Freemius / widgets core.
4. Comparar flag `off` vs `on` sobre el mateix dump de `wp_options` de staging (comptar unconfirmed, mismatches, grups).

---

## 11. Riscos i mitigacions

| Risc | Mitigació |
|------|-----------|
| Regressions massives | Flag off/shadow/on; Fase 0 no toca el detector |
| Unconfirmed fals per doble candidat del mateix plugin | Merge per owner_token **abans** del marge |
| Lentitud batch | G0 short-circuit; G15 only slow; cache per clau |
| Mapes legacy incorrectes | validació + no promoure veïns en Confirmar |
| Fallback TSO | eliminar al camí v2; fixture `tso_generic_no_fallback` |
| Recursió widget | G5 no crida `detect_plugin` |
| Plugin Check / prefix | Cap símbol nou curt; `tsootc_` / `TSOOTC_` |
| Fast batch ignora el gate | resolver v2 degrada també en `fast` |

---

## 12. Preguntes obertes (tancades per v1.1 / pendents)

| # | Pregunta draft v1 | Decisió v1.1 |
|---|-------------------|--------------|
| 1 | Recalibrar threshold 35? | Mantenir 35 + marge 10 fins a dades de staging (Fase 2). |
| 2 | Unconfirmed: grup separat o badge? | **Grup existent** per unconfirmed; badge per medium dins del plugin. |
| 3 | Confirmar promou prefix? | **No.** Només clau exacta. |
| 4 | Codescan AST abans del switch? | **Després** (Fase 4 / RFC v2). |
| 5 | Taules extra al mateix motor? | RFC v2; copiar-ne l’API ja ara. |

---

## 13. Decisió demanada

Abans d’implementar Fase 1 (el motor):

- [x] **Arquitectura** candidats + merge per token + resolver — camí únic (revisat).
- [x] **Flag** off/shadow/on via filter + opció, no wp-config obligatori.
- [x] **UI unconfirmed:** grup existent + badge medium + filtre.
- [x] **Confirmació:** només clau exacta.

Abans d’implementar Fase 0 (grouping): cap decisió extra; és correcció del bug de mostra mixta.

---

## Annex A — Mapa fitxers (post-migració)

```
includes/
  tso-detection-engine.php      ← resolve_option, collect, filter, merge, finalize
  tso-detection-generators.php  ← G0..G15 (embolcalls; Fase 1)
  tso-detection-rules.php       ← branded registry (Fase 4, no abans)
  tso-detection-score.php       ← pesos + compute (existent, constants margin)
  tso-detection-regression.php  ← fixtures
  tso-table-detection.php       ← referent d’API; unificar a RFC v2
  tso-core.php                  ← detect_plugin shim; grouping per owner_token
  tso-audit.php                 ← mateix token + evidence_summary
```

---

## Annex B — Què s’ha canviat respecte el draft v1

1. Afegit pas **MERGE per owner_token** (buit crític).
2. `custom_map` ≠ `option_key_map` pel que fa a confiança.
3. Generadors que el draft ometia: known exact map, external_updates, FASE 3, autodetect prefix, TSO fallback, recursió widget.
4. Fases reordenades: grouping primer; shadow real; DSL de regles l’últim.
5. Pesos codescan conservadors.
6. Flag sense escriure `wp-config.php`.
7. Fast batch ha de poder tornar unconfirmed.
8. UI: el grup unconfirmed ja existeix.
9. Model d’API copiat de taules extra, no a l’inrevés.

---

*Fi RFC v1.1 — següent pas d’implementació: Fase 0 (owner_token grouping) i, en paral·lel o just després, Fase 1 (shadow engine).*

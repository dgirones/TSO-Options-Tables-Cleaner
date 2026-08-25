# RFC v1 — Motor unificat de detecció wp_options

**Producte:** TSO Options & Tables Cleaner (`tso-options-tables-cleaner`)  
**Estat:** Draft intern (v1)  
**Autor:** TSO / Cursor agent  
**Data:** 2026-08-26  
**Àmbit:** Detecció de propietari per claus `wp_options` (pestanya Options + auditoria + Confirmar/Assignar)

---

## 1. Resum executiu

Avui la detecció té **dues arquitectures paral·leles**:

| Camí | Fitxer(s) | Comportament |
|------|-----------|--------------|
| **Cascada** | `tsootc_detect_plugin()` → `tsootc_detect_plugin_with_history()` | ~15 fases; **el primer match guanya** i fa `return`. |
| **Scoring** | `tso-detection-score.php` | Recull candidats, puntua, tria guanyador amb llindar + marge. |

El scoring només s’usa parcialment (gate al final, AJAX Confirmar, rescat quan la cascada és feble). Això provoca:

- Sobreescriptures (p.ex. `theme_mods_*` → plugin per codescan o prefix curt).
- Falsos positius en prefixos curts (`tso` del tema vs plugins TSO).
- Conflictes de “mostra mixta” dins d’un grup quan dues fonts donen el mateix propietari amb formes diferents (`folder` vs `file`).
- Dificultat per mantenir regles (`detect_*` disperses vs `tso-detection-score.php` incomplet).

**Proposta:** un **motor únic** basat en **candidats + evidència + puntuació**, on la cascada actual passa a ser només un **registre de generadors de candidats**.

---

## 2. Objectius

1. **Un sol punt d’entrada** per a la pestanya Options: `tsootc_detection_resolve_option( $option_name, $inventory, $args )`.
2. **Mai assignar automàticament** quan dos candidats tenen puntuació similar (marge configurable).
3. **Evidència explícita** per candidat (per debug, auditoria i UI).
4. **Guards estructurals** (theme vs plugin, hosting/SDK sintètic) com a regles de rebuig, no com a patches post-hoc.
5. **Coherència de grup**: pass post-agrupació per outliers i grups heterogenis.
6. **Compatibilitat**: mapes manuals (`custom_map`), `option_key_map`, historial i codescan continuen funcionant; només canvia l’ordre de decisió.
7. **Regressió**: cada bug real → fixture a `tso-detection-regression.php`.

## 3. No-objectius (v1)

- Refactor de detecció de **taules extra** (RFC separat; mateix patró després).
- ML / embeddings / serveis externs.
- Canvi del model d’emmagatzematge de mapes (DB schema).
- Reescriure codescan complet (AST `update_option` → fase 2 del RFC).

---

## 4. Estat actual (referència)

```
option_name
    │
    ├─► tsootc_detect_plugin()          ← cascada, return early
    │       custom_map → TSO → Woo → … → slug → prefix → null
    │
    └─► tsootc_detect_plugin_with_history()
            reconcile label
            codescan cache (pot sobreescriure tema)
            history enhance
            correct_theme / correct_plugin
            confidence_gate (parcial; fast batch el salta en part)
```

**Fitxers clau:**

- `includes/tso-core.php` — `tsootc_detect_plugin()`, agrupació, AJAX confirm/assign.
- `includes/tso-detection-score.php` — scoring parcial, `TSOOTC_DETECTION_SCORE_THRESHOLD` (35).
- `includes/tso-audit.php` — auditoria (ja fa mostra mixta, tema vs plugin).
- `includes/tso-code-scan.php` — índex grep / mapping.
- `includes/tso-tracking.php` — historial, `option_key_map` validation.
- `includes/tso-detection-regression.php` — fixtures.

---

## 5. Arquitectura proposada

### 5.1 Flux

```
option_name + inventory + args{fast|slow}
    │
    ▼
[1] GENERATORS ──► array<Candidate>
    │                 cada un: row + evidence[] + base_score
    ▼
[2] FILTERS ─────► descarta invàlids estructuralment
    │                 (theme_mods → mai plugin; synthetic → N/A disc; option_key_map invalid)
    ▼
[3] SCORER ──────► score final per candidat
    ▼
[4] RESOLVER ────► winner | unconfirmed | null
    │                 winner només si score ≥ threshold AND (best - second) ≥ margin
    ▼
[5] POST-PROCESS ► reconcile disk, theme label, history label (sense canviar owner)
    │
    ▼
detection row (+ confidence_score, evidence summary)
```

Després, a nivell de **pestanya** (batch):

```
[6] GROUP ───────► agrupar per group_key (com ara)
[7] GROUP COHERENCE ► outlier / split / flag "mixed"
```

### 5.2 Tipus de dades (PHP arrays)

```php
// Candidat (intern, no persistit).
array(
    'row'       => array( /* detection row actual */ ),
    'evidence'  => array(
        array( 'type' => 'custom_map', 'detail' => 'manual assign' ),
        array( 'type' => 'codescan', 'detail' => 'plugins/foo/foo.php:update_option' ),
    ),
    'score'     => 78,
    'generator' => 'tsootc_detection_gen_codescan_cache',
);

// Row resolta (sortida pública).
array(
    'name'             => 'WooCommerce',
    'file'             => 'woocommerce/woocommerce.php',
    'folder'           => 'woocommerce',
    'source'           => 'autodetect',
    'confidence_score' => 78,
    'confidence'       => 'high', // high | medium | low | unconfirmed
    'evidence_summary' => 'prefix_match, file_on_disk',
);
```

### 5.3 Generadors (v1 — migració des de cascada)

Cada generador retorna **0..N candidats**, mai un `return` definitiu.

| ID | Generador | Origen actual | Notes |
|----|-----------|---------------|-------|
| G0 | `gen_custom_map` | FASE 0 | Puntuació màxima; trusted |
| G1 | `gen_branded_rules` | Registre únic (nou) | Woo, Jetpack, TSO, Freemius, Softaculous, TML… |
| G2 | `gen_theme_mods` | FASE 0a3 | Sempre `type=theme`; blocat vs plugin |
| G3 | `gen_option_key_map` | persistent evidence | Validació `option_key_map_entry_is_valid` |
| G4 | `gen_widgets` | widget autodetect | |
| G5 | `gen_theme_heuristics` | theme row resolvers | The7, legacy frameworks |
| G6 | `gen_slug_inventory` | FASE 1 slug match | Variants `-`/`_` |
| G7 | `gen_prefix_map` | FASE 2 prefix map | Score baix per defecte |
| G8 | `gen_bootstrap_basename` | FASE 0e | |
| G9 | `gen_history` | tracking | Només si folder/file encara coherent |
| G10 | `gen_codescan_cache` | code-scan index | **No** per `theme_mods_*` |
| G11 | `gen_codescan_live` | slow only | grep/scan en viu |

**Ordre d’execució dels generadors:** tots en paral·lel (collect), no cascada.

### 5.4 Filtres estructurals (hard rejects)

| Regla | Acció |
|-------|-------|
| `theme_mods_{slug}` | Rebutja candidats `type !== theme` o `folder` sense `theme:` |
| `option_key_map_entry_is_valid` === false | score = 0 |
| Prefix genèric (< 5 chars) sense evidència de disc | score capped ≤ 20 |
| Synthetic folder (`__hosting__`, `__freemius__`, …) | `on_disk = null`; no mismatch |
| Plugin folder evidence required (`tsootc_option_key_matches_plugin_folder_evidence`) | Rebutja match feble |

### 5.5 Puntuació (evolució de `tso-detection-score.php`)

Constants (filtrables):

```php
TSOOTC_DETECTION_SCORE_THRESHOLD = 35;  // existent
TSOOTC_DETECTION_SCORE_MARGIN    = 10;  // existent a pick_scored_winner
```

Pesos base per `evidence.type` (proposta):

| Evidència | Pes |
|-----------|-----|
| custom_map | 100 (trusted, bypass resolver) |
| option_key_map | 90 |
| theme_mods_exact | 95 |
| codescan_update_option | 85 |
| codescan_string | 50 |
| history_index | 40 |
| slug_prefix_match | 35 |
| prefix_map_label_only | 15 |

Bonificacions: `+25` file amb `/`, `+15` file exists, `+30` theme_mods slug match.  
Penalitzacions: `row_is_weak` → cap 20; candidat invalidat per map → 0.

### 5.6 Resolució

```php
function tsootc_detection_resolve_option( $option_name, $installed_plugins, $args ) {
    $candidates = tsootc_detection_collect_all_candidates( ... );
    $candidates = tsootc_detection_apply_structural_filters( ... );
    $candidates = tsootc_detection_score_candidates( ... );

    // Trusted: custom_map / option_key_map confirmats → retorn directe.
    $trusted = tsootc_detection_pick_trusted( $candidates );
    if ( $trusted ) {
        return $trusted;
    }

    $winner = tsootc_detection_pick_scored_winner_from( $candidates );
    if ( $winner ) {
        return tsootc_detection_finalize_row( $winner, ... );
    }

    // Dubtós: 2+ candidats dins del marge → unconfirmed + hints.
    return tsootc_detection_build_unconfirmed_row( $option_name, $best_hint );
}
```

### 5.7 Coherència de grup

Després d’agrupar per `detected['name']` / folder:

1. Per cada grup amb ≥ 3 claus: calcular **owner token** dominant (`folder` normalitzat).
2. Clau amb token diferent del 80% del grup → `confidence = low`, badge outlier.
3. Si cap token ≥ 60% → subdividir o marcar grup `mixed` (com auditoria, però a la llista principal).

Funció proposada: `tsootc_detection_reconcile_option_groups( &$grouped_ordered, $inventory )`.

---

## 6. Canvis d’API (compatibilitat)

| Actual | Nou (v1) | Transició |
|--------|----------|-----------|
| `tsootc_detect_plugin()` | Wrappa `resolve_option()` retornant només `row` | Deprecated soft; mantenir 2 versions |
| `tsootc_detect_plugin_with_history()` | `resolve_option()` + history post-process | Mateix |
| `tsootc_detection_collect_scored_candidates()` | `collect_all_candidates()` | Ampliar fonts |
| `tsootc_detection_apply_confidence_gate()` | Lògica dins resolver | Eliminar quan migrat |

**Feature flag** (opció admin o constant):

```php
define( 'TSOOTC_DETECTION_ENGINE_V2', false ); // true per staging
```

Permet A/B: mateixa clau, comparar cascada vs resolver en log de debug.

---

## 7. UI / UX

| Estat | Com es mostra |
|-------|----------------|
| `confidence: high` | Grup normal |
| `confidence: medium` | Icona `?` + tooltip evidència |
| `confidence: unconfirmed` | Grup “Sense confirmar” o sub-badge |
| `group: mixed` | Avís al header del grup (no només auditoria) |
| Confirmar | Desa a `option_key_map` + puja a trusted |
| Assignar | Desa a `custom_map` + trusted |

Auditoria: columna **Evidència** (resum) en lloc de només “Mètode”.

---

## 8. Rendiment

| Mode | Generadors actius |
|------|-------------------|
| `fast` (llista Options) | G0–G9 + G10 (cache). **No** G11. |
| `slow` (Confirmar, auditoria, primera càrrega) | Tots + G11 |

- Cache per clau: `{option}|{fast|slow}` (ja existeix parcialment a `$GLOBALS['tsootc_opts_detect_cache']`).
- Invalidar cache quan: bump mapes, schema, codescan index, plugin install/delete (hooks existents).
- Generadors registrats: evitar recórrer 15 funcions sequencialment quan G0 ja dóna trusted.

---

## 9. Registre de regles (substitueix `detect_*` dispersos)

Fitxer nou proposat: `includes/tso-detection-rules.php`

```php
function tsootc_detection_get_branded_rules() {
    return array(
        array(
            'id'       => 'woocommerce_payments',
            'match'    => array( 'prefix' => 'wcpay_' ),
            'owner'    => array( 'folder' => 'woocommerce-payments', 'fallback' => 'woocommerce' ),
            'never_theme' => true,
        ),
        // ...
    );
}
```

Cada regla = 1 generador + 1 fixture mínim.

---

## 10. Pla de migració (fases)

### Fase A — Infraestructura (sense canvi de comportament visible)
- [ ] Crear `tsootc_detection_resolve_option()` amb flag `TSOOTC_DETECTION_ENGINE_V2=false`.
- [ ] Implementar `collect_all_candidates()` cridant generadors G0–G3 com a prova.
- [ ] Ampliar fixtures: tso-theme vs tso_*, Softaculous, mostra mixta plugin propi.

### Fase B — Paritat
- [ ] Migrar generadors G4–G11 des de cascada.
- [ ] Mode debug: log diff cascada vs resolver per admin.
- [ ] Activar V2 en staging; comparar auditoria mismatch count.

### Fase C — Switch per defecte
- [ ] `TSOOTC_DETECTION_ENGINE_V2=true` per defecte.
- [ ] `tsootc_detect_plugin()` → wrapper primari.
- [ ] Coherència de grup (§5.7) a pestanya Options.

### Fase D — Neteja
- [ ] Eliminar returns early redundants de cascada (o reduir `detect_plugin` a shim).
- [ ] UI confiança + filtre “Només dubtoses”.
- [ ] RFC v2: codescan AST + taules extra.

---

## 11. Proves

1. **`tso-detection-regression.php`** — obligatori al DoD quan `TSOOTC_WP_LOAD` definit.
2. **Fixtures nous (mínim):**

| ID | option | Assert |
|----|--------|--------|
| `theme_mods_tso_theme` | `theme_mods_tso-theme` | `type=theme`, `folder=theme:tso-theme`, forbidden plugin file |
| `tso_plugin_history_self` | `tso_options_tables_cleaner_plugin_history` | same folder token for all samples in group |
| `softaculous_hosting` | `softaculous_*` | synthetic, `on_disk=null` |

3. **Smoke manual:** activar plugin → Options tab → auditoria 0 conflictes esperats per grups coneguts.

---

## 12. Riscos i mitigacions

| Risc | Mitigació |
|------|-----------|
| Regressions massives en llocs grans | Feature flag + fast mode sense G11 |
| Lentitud batch | Trusted short-circuit; cache per clau |
| Mapes legacy incorrectes | `option_key_map_entry_is_valid` + no promoure veïns |
| Plugin Check / prefix | Cap símbol nou curt; mateix prefix `tsootc_` |

---

## 13. Preguntes obertes

1. **Threshold 35** — cal recalibrar quan tots els generadors aporten candidats?
2. **Unconfirmed a la UI** — grup separat o dins del plugin dubtós?
3. **Promoció automàtica** — quan l’admin confirma una clau, promoure prefix exacte (`woocommerce_*`) automàticament o només clau exacta?
4. **Codescan v2** — prioritat abans o després del switch V2?
5. **Taules extra** — mateix motor amb adaptador `table_name` → RFC v2?

---

## 14. Decisió demanada

Abans d’implementar Fase A:

- [ ] **Aprovar** arquitectura candidats + resolver com a camí únic.
- [ ] **Aprovar** feature flag `TSOOTC_DETECTION_ENGINE_V2`.
- [ ] **Triar** UI unconfirmed: grup separat vs badge (recomanació: badge + filtre).
- [ ] **Triar** confirmació: només clau exacta (recomanació: sí, sense promoure prefix automàtic).

---

## Annex A — Mapa fitxers (post-migració)

```
includes/
  tso-detection-engine.php      ← resolve_option, collect, filter, finalize
  tso-detection-generators.php  ← G0..G11
  tso-detection-rules.php       ← branded rules registry
  tso-detection-score.php       ← pesos + compute (existent, ampliat)
  tso-detection-regression.php  ← fixtures (existent)
  tso-core.php                  ← detect_plugin shim; grouping + coherence
  tso-audit.php                 ← usa resolve_option + evidence_summary
```

---

*Fi RFC v1 — següent pas: implementar Fase A (infra + flag + 3 fixtures) si s’aprova.*

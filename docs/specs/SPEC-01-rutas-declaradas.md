# SPEC 01 — Rutas declaradas, `immutable` y `secret`

**Estado:** propuesta, sin implementar.
**Origen:** sesión de arquitectura del núcleo de plataforma (69 modelos, 22 rondas).
**Depende de:** nada. Se puede implementar hoy y vale por sí sola.
**Bloquea a:** SPEC 02 (manifiesto de contrato) y a la generación del núcleo.

---

## 1. El problema

LaraPack genera diez acciones por modelo: `policies`, `policy`, `index`, `show`, `create`, `update`, `delete`, `restore`, `forceDelete`, `export`. Esa forma es correcta para una entidad que un humano administra desde una pantalla.

Pero hay tablas que **por diseño** no se comportan así:

- **Sólo se leen.** Sus filas las escribe una operación de instalación, no un formulario. Un catálogo de permisos, de claves de configuración, de tipos de evento.
- **Sólo se agregan.** Nacen y no se tocan nunca. Una bitácora de auditoría, un registro de entrega, una versión congelada.
- **Se otorgan y se revocan, pero no se editan.** Corregir una concesión no es actualizarla: es cerrarla y abrir otra, porque la fila anterior es evidencia de lo que estuvo vigente.
- **No se leen enteras.** Una credencial se lista y se revoca; su secreto no sale nunca.

Medido sobre un sistema real de 69 tablas: **sólo 18 encajan en la forma por defecto**. Cinco se cubren con `pivots`. Las otras **46 no tienen forma en el generador**.

Hoy la única salida es generar de más y **borrar a mano** el `update`, el `delete` y el formulario de edición. Eso deja los archivos marcados como `customised` para siempre y desincroniza el contrato del código: es exactamente la deriva que LaraPack existe para impedir, ocurriendo en el hueco donde LaraPack no tiene nada que decir.

---

## 2. Qué se añade al esquema

### 2.1 `routes` — a nivel de modelo

```json
{
    "name": "AuditEvent",
    "routes": { "only": ["index", "show", "export"] },
    "props": [ ... ]
}
```

```json
{
    "name": "ScopeRoleAssignment",
    "routes": { "except": ["update", "restore", "forceDelete"] },
    "props": [ ... ]
}
```

Fragmento para `laraimport.schema.json`:

```json
"routes": {
    "type": "object",
    "additionalProperties": false,
    "description": "Qué acciones genera el modelo. Por omisión, las diez.",
    "properties": {
        "only":   { "type": "array", "items": { "$ref": "#/$defs/routeAction" }, "uniqueItems": true },
        "except": { "type": "array", "items": { "$ref": "#/$defs/routeAction" }, "uniqueItems": true }
    },
    "not": { "required": ["only", "except"] }
}
```

```json
"routeAction": {
    "type": "string",
    "enum": ["policies", "policy", "index", "show", "create",
             "update", "delete", "restore", "forceDelete", "export"]
}
```

- `only` y `except` son **excluyentes**: declarar las dos es error de validación.
- Ausencia de `routes` = las diez, que es el comportamiento actual. **La clave es retrocompatible.**

### 2.2 `immutable` — a nivel de modelo

```json
{ "name": "AuditEvent", "immutable": true, "routes": { "only": ["index", "show"] } }
```

```json
"immutable": {
    "type": "boolean",
    "default": false,
    "description": "La fila no se modifica ni se borra nunca. Genera el modelo con update() y delete() deshabilitados."
}
```

### 2.3 `secret` — a nivel de propiedad

```json
{ "name": "token_hash", "type": "string", "secret": true }
```

```json
"secret": {
    "type": "boolean",
    "default": false,
    "description": "Nunca sale por la API. El Resource generado no la incluye y verify falla si alguien la añade."
}
```

---

## 3. Qué cambia en la generación

`routes` **cascadea**. Quitar una acción no es sólo quitar una línea del archivo de rutas: es no generar todo lo que cuelga de ella.

| Acción ausente | Deja de generarse |
|---|---|
| `create` | `CreateRequest`, ruta `POST create`, `CreateForm`, vista `Create`, la acción del datatable |
| `update` | `UpdateRequest`, ruta `PUT update`, `EditForm`, vista `Edit`, la acción del datatable |
| `delete` | `DeleteRequest`, ruta `DELETE delete`, la acción del datatable |
| `restore` | `RestoreRequest`, ruta `POST restore` |
| `forceDelete` | `ForceDeleteRequest`, ruta `DELETE force-delete` |
| `export` | `ExportRequest`, `Exports`, `ExportNotification`, ruta y acción |
| `index` | `IndexRequest`, ruta, `DataTable`, vista `Admin`, `FilterForm` |
| `show` | `ShowRequest`, ruta, vista `Show`, `ModelProfile` |
| `policies` / `policy` | Su request y su ruta |

Reglas de coherencia que **el validador debe exigir**:

- Sin `index` y sin `show` no hay módulo de interfaz que valga: se avisa.
- `restore` y `forceDelete` sin `delete` es incoherente: error.
- `create` o `update` presentes con `immutable: true`: error.
- Una `prop` con `form: true` cuando no hay ni `create` ni `update`: error, porque el input no tiene dónde vivir.
- `export` sin `index`: aviso, la exportación usa los mismos filtros.

`immutable: true` genera además:

- El modelo con `update()`, `delete()`, `forceDelete()` y `restore()` sobrescritos para lanzar excepción.
- Un test que **falla si alguien reactiva la escritura**, por ruta o por modelo.
- Sin observer de `updating` / `deleting`, que no pueden ocurrir.

`secret: true` en una propiedad:

- La excluye del `Resource` generado.
- La excluye de `$fillable` sólo si además es `fillable: false`; el secreto se escribe, pero no se lee.
- La excluye de `exports_cols` y de `datatable` siempre.

---

## 4. Qué comprueba `verify`

Tres comprobaciones nuevas, con código de salida distinto de cero:

| Código | Detecta |
|---|---|
| `route-not-declared` | Existe una ruta, request o formulario de una acción que el JSON no declara. Alguien la escribió a mano. |
| `immutable-write` | Un modelo `immutable` tiene una ruta de escritura, o una llamada a `update()`/`delete()` sobre él en cualquier archivo del paquete. |
| `secret-exposed` | Una propiedad `secret` aparece en un `Resource`, en un `export`, o en un `datatable`. |

La segunda es la que de verdad importa: **quitar la ruta impide entrar por HTTP, pero no impide que un servicio del propio paquete llame a `update()`**. La invariante no es «no hay endpoint», es «esta fila no se modifica nunca», y sólo se sostiene si se comprueba.

---

## 5. Criterios de aceptación

Se considera terminado cuando:

1. `larapack:schema` incluye `routes`, `immutable` y `secret` con su documentación.
2. Un `laraimport.json` **sin** ninguna de las tres claves genera exactamente lo mismo que antes. Hay un test que lo comprueba archivo por archivo, por hash.
3. `{"routes": {"only": ["index","show"]}}` genera dos rutas, dos requests, ninguna vista de alta ni de edición, y el `index.js` del módulo no exporta `create` ni `update`.
4. `{"only": [...], "except": [...]}` en el mismo modelo falla en `larapack:validate` con mensaje claro.
5. `immutable: true` con `create` en `routes.only` falla en `validate`.
6. Un modelo `immutable` al que se le añade a mano una ruta `PUT` hace fallar `larapack:verify` con `immutable-write`.
7. Una propiedad `secret` añadida a mano al `Resource` hace fallar `verify` con `secret-exposed`.
8. `API_ROUTE_PREFIX` y la comprobación `route-prefix` siguen funcionando con rutas parciales.

---

## 6. Lo que este spec NO pide

- **No pide una clave `kind`** ni ninguna taxonomía de tipos de modelo. Se consideró y se descartó: nombrar cinco categorías a partir de un solo sistema mete una clasificación adivinada dentro del generador. `routes` + `immutable` + `secret` son primitivas y componen cualquier caso. Si con el tiempo tres combinaciones se repiten en todos los paquetes, ahí se les pone nombre **como atajo sobre las primitivas**, no en su lugar.
- **No pide tocar el manifiesto de contrato.** Eso es el SPEC 02 y es independiente.
- **No pide nada del núcleo de plataforma.** Este cambio vale igual para cualquier paquete del ecosistema.

---

## 7. Por qué vale la pena aunque el núcleo nunca se escriba

Las tablas que sólo se leen o sólo se agregan no son una rareza: aparecen en cualquier sistema serio. Una póliza, un consentimiento, un movimiento contable, una autorización temporal, un registro de envío.

Cada vez que aparece una hoy, alguien la declara normal y luego borra a mano lo que sobra. Con este cambio, se declara una vez y el generador hace lo correcto siempre — que es la razón de existir de LaraPack, aplicada al caso que hoy se le escapa.

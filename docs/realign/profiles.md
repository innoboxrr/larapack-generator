# Los tres perfiles de proyecto

Parte de [`larapack:realign`](README.md). El recorrido es el mismo para
cualquier proyecto —`diagnose`, `plan`, `next`, `check`—, pero dónde se gasta
el tiempo depende de dónde parte. Hay tres perfiles, y `diagnose` dice en cuál
estás sin preguntarle a nadie:

| Perfil | Cómo se reconoce en el diagnóstico | Dónde se va el trabajo |
|---|---|---|
| [Con contrato y manifiesto al día](#1-con-contrato-y-manifiesto-al-día) | Casi todo `identical`/`format_only`; pocos `version_delta`; `hole_edited` alto. | `adopt` y `apply`; poco guiado. |
| [Con contrato, de una versión vieja y con deuda](#2-con-contrato-de-una-versión-vieja-y-con-deuda) | Muchos `adopt` por formato, `prune`, `fix`, cientos de `controller-shape`, `pin-foreign-keys`. | Los pasos guiados `controller-shape`. |
| [Sin contrato](#3-sin-contrato) | `no-contract` en la raíz y en los paquetes; mucho `unmanaged-http` y `unmanaged-models`. | `infer` y la decisión de adoptar. |

Las cifras son de copias de proyectos reales, ensayadas sin tocar los
originales (CHANGELOG de 9.4.0).

## 1. Con contrato y manifiesto al día

El caso fácil: el proyecto nunca dejó de ser LaraPack. Conserva el contrato y
el manifiesto, el front generado está intacto y la divergencia es de formato,
de versión del generador o de huecos editados (lo correcto).

**Recorrido recomendado:**

```bash
git switch -c realign
larapack:realign diagnose --jobs=4
larapack:realign plan
larapack:realign apply --deterministic --dry-run
larapack:realign apply --deterministic          # adopt, regenerate, fix, endpoint-tests, controller-concern, model-lists
# pruebas del proyecto; commit
larapack:realign diagnose
larapack:realign plan
larapack:realign apply --deterministic          # la segunda pasada adopta lo que dejó igual la primera
# pruebas; commit
larapack:realign next                           # lo guiado: edited-outside por familia, algún controller-shape
larapack:realign check <id>
# … y las decisiones, al final, con el dueño
larapack:verify --strict
```

**Qué esperar** (un monorepo de 82 ámbitos y 22 846 archivos):

- `diagnose`: 283 s la primera vez, 116 s con la caché.
- Primera pasada de `apply`: 5 843 cambios en 578 s (3 640 adoptados, 983
  regenerados, 691 tests completados, 447 `fix`, 19 traits movidos, 14
  modelos), ninguno saltado. Segunda: 608.
- Cumplen: de 16 921 a 21 757 de 22 844. `verify`: de 1 975 avisos y errores
  a 20. `edited_outside`: de 404 a 207.
- Queda: 24 pasos `edited-outside`, 4 `controller-shape`, 1
  `declared-routes` y 77 decisiones (closures de la SPA según el Host,
  paquetes sin modelos, `#[HandRolled]`, controladores escritos a mano).

**Cuidado con:** trabajar por paquete (`--package`) y comitear por paquete en
un monorepo; las decisiones de rutas a mano necesitan conservar su
`route_name` y su `uri` ([contract.md](contract.md)); un `import` posterior
puede escribir alteraciones: revísalas ([risks.md](risks.md#migraciones-y-datos)).

## 2. Con contrato, de una versión vieja y con deuda

Un proyecto generado con 7.x, que pasaba Pint después de cada import (el
manifiesto guarda el hash de antes), con modelos retirados del contrato, un
front generado atrasado y mucha lógica escrita en los controladores.

**Recorrido recomendado:**

```bash
git switch -c realign
larapack:realign diagnose
larapack:realign plan
larapack:realign status                         # mira pin-foreign-keys y prune antes de nada
larapack:realign apply --deterministic --dry-run
larapack:realign apply --deterministic          # adopt (formato), prune (lo retirado), regenerate, fix, endpoint-tests, model-lists, pin-foreign-keys
# pruebas; commit
larapack:realign diagnose
larapack:realign plan
larapack:realign apply --deterministic
# pruebas; commit
larapack:realign next                           # controller-shape, un controlador por paso, con controller-to-request
larapack:realign check controller-shape@<archivo>
```

**Qué esperar** (un proyecto 7.x de 3 550 archivos):

- `diagnose`: 128 s.
- Primera pasada: 2 272 cambios en 47 s (1 914 adoptados por formato, 94
  olvidados —un modelo retirado y 41 alteraciones—, 38 regenerados, 140 `fix`,
  70 tests, 14 modelos, **56 llaves fijadas** en el contrato), 3 saltados
  (closures que no son el de la SPA: una decisión). Segunda: 140.
- Cumplen: de 1 185 a 3 228 de 3 456. `verify`: de 699 a 380, y 377 de esos
  380 son `controller-shape`.
- Queda: **77 pasos `controller-shape`** (377 hallazgos), 6 `edited-outside` y
  5 decisiones.

**Dónde está el trabajo:** los controladores escritos a mano. Cada uno es un
paso guiado con su playbook (`controller-to-request` y sus anexos), las
pruebas antes y después y el aviso del **403 antes que 422**. A 10–15 minutos
por acción con agente y revisión, es lo que más cuesta, y no se automatiza: es
el trabajo de verdad.

**Cuidado con:** `pin-foreign-keys` antes de cualquier `import` (si no, se pasa
de borrar en cascada a restringir); las alteraciones vacías de 9.x que el
realineamiento no borra (pide la base de producción); las rutas a mano que
llama el front propio (339 de 401 en el proyecto ensayado): se adoptan con su
`route_name` y su `uri`, o se quedan como código propio.

## 3. Sin contrato

Un proyecto que nunca usó LaraPack, con convenciones parecidas escritas a mano:
controladores que delegan en requests, modelos con traits, rutas por archivo.
No hay contrato con el que comparar: el diagnóstico lo dice (`no-contract`) y
lista el código HTTP y de modelos.

**Recorrido recomendado:**

```bash
larapack:realign diagnose                        # segundos: no hay nada que regenerar
larapack:realign plan
larapack:realign infer no-contract@. --dry-run
larapack:realign infer no-contract@.             # laraimport.inferred.json en la raíz y en cada paquete
# leer .larapack/realign/infer/<ámbito>.json: decisiones, not_inferred, lost
# decidir, con el dueño, ámbito por ámbito:
larapack:realign decide no-contract@. infer --note="…"
#   o dejarlo fuera:
larapack:realign decide no-contract@. keep --note="…"
larapack:realign except no-contract@. --option reason="Se queda fuera de LaraPack"
```

Si se adopta: completar el borrador (lo `not_inferred`: formularios,
etiquetas, habilidades de la política), copiarlo a `laraimport.json`,
`larapack:validate`, `larapack:import --dry-run` (revisar que no reescriba lo
que ya existe y qué alteraciones escribiría), importar, y desde ahí es el
perfil 1 ó 2: `diagnose`, `plan`, `adopt`… Los pasos están en
[infer.md](infer.md#revisar-el-borrador-y-adoptarlo).

**Qué esperar** (un proyecto de 20 ámbitos sin contrato):

- `diagnose`: 3 s. 250 controladores, 205 modelos y 41 archivos de rutas sin
  gestionar; 208 pasos (196 controladores).
- `infer`: 20 de 20 borradores válidos (426 modelos, 5 201 props, 794
  relaciones, 1 330 índices, 30 pivotes), menos de 10 s por ámbito.
- **Ninguna ruta se conserva** si se sirven bajo un prefijo que no es el de
  LaraPack (`api/v1/…` con nombres propios): 913 rutas quedan como decisión.

**La alternativa honesta:** dejar el proyecto fuera de LaraPack (`keep`) y
quedarse con lo que sí vale sin contrato: `larapack:verify` comprueba la forma
de **todos** los controladores (`controller-shape`, `request-handle`,
`controller-concern`), aunque no los haya generado LaraPack. Es la guardia más
barata contra la deriva, y no obliga a adoptar nada.

**Cuidado con:** la interfaz y la autorización no se infieren (adoptar y
regenerar el front daría pantallas sin campos; un endpoint de modelo nace con
una política que niega); `table_prefix` se deduce y hay que revisarlo; los
modelos a mano tienen lógica (scopes, `boot`) que el contrato no describe y
que un `import --force` reemplazaría. Todo en
[infer.md](infer.md#limitaciones-y-riesgos).

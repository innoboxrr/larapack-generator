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

Antes del perfil, [el orden exacto de punta a punta](#el-orden-exacto-de-punta-a-punta),
que es el mismo en los tres: el perfil sólo dice dónde se va el tiempo. Las
órdenes se escriben `larapack:…`; delante va `vendor/bin/builder`,
`php artisan` o `php <clon>/builder … --root=<proyecto>` según dónde esté el
paquete ([cómo se invoca](README.md#cómo-se-invoca-con-el-paquete-en-vendor-o-desde-un-clon)).

Las cifras son de copias de proyectos reales, ensayadas sin tocar los
originales (CHANGELOG de 9.4.0). Los tres recorridos, de `diagnose` a
`larapack:realign done`, están además en una prueba de punta a punta del paquete
(`RealignProfilesTest`), sobre proyectos sintéticos con las formas de los
reales.

## El orden exacto de punta a punta

Para cualquier perfil, persona o agente. Cada número se termina antes de pasar
al siguiente; los números 4 a 7 se repiten hasta que no queda nada.

1. **Preparar.** Una rama (`git switch -c realign`) y el árbol limpio. Si el
   paquete no está en `vendor/`, el clon con su `composer install` y
   `--root=<proyecto>` en cada orden. Comprueba si puedes correr las pruebas
   del proyecto; si no, cada paso guiado que cambia comportamiento quedará
   [sin probar](guided.md#sin-pruebas-cuando-no-se-pueden-correr-las-del-proyecto).
2. **Dónde estás.** `larapack:realign status`. Si hay plan y no está viejo,
   sigue en el número que toque; si no, el 3.
3. **Sin contrato** (`no-contract`): el perfil 3. `infer`, la decisión del
   dueño y, si se adopta, el import; desde ahí, el 4.
4. **Diagnosticar y planificar.** `larapack:realign diagnose` y
   `larapack:realign plan`.
5. **Lo determinista.** `larapack:realign apply --deterministic --dry-run`,
   después sin `--dry-run`; lo saltado, a mano con `how.fallback` de `next`.
   Pruebas del proyecto y commit. **Vuelve al 4** hasta que `apply
   --deterministic` no tenga nada que aplicar (casi siempre, dos pasadas).
6. **Lo guiado, uno por uno.** `larapack:realign next`; seguir el playbook
   (`how.instructions`) con las pruebas antes y después;
   `larapack:realign check <id>`; un commit por paso. Al terminar los de un
   ámbito, y siempre que hayas copiado lo regenerado o cambiado el contrato,
   **vuelve al 4**.
7. **Las decisiones.** Cuando `next` da una `decision`: presentarla al dueño
   (no decidir), `larapack:realign decide` cuando él decida, lo que pida la
   opción (su orden o su playbook) y, si es dejarlo así, la excepción pedida
   que **aprueba el dueño**. Lo que la opción cambie (un contrato, un
   controlador movido) pide **volver al 4**.
8. **Terminar.** `next` dice `done` después de una última pasada del 4 que
   no deja nada pendiente; entonces `larapack:verify --strict`
   ([verify.md](verify.md#cuándo-se-termina)) y la lista de lo que quedó
   [sin probar](guided.md#sin-pruebas-cuando-no-se-pueden-correr-las-del-proyecto),
   si hay.

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
# un commit por paso; tras los guiados (y tras copiar lo regenerado), otra pasada:
larapack:realign diagnose
larapack:realign plan
larapack:realign apply --deterministic
# … y las decisiones, al final, con el dueño
larapack:realign done                           # el criterio de terminado: 0, o lo que falta
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
  paquetes sin modelos, `#[HandRolled]`, controladores y archivos de rutas
  escritos a mano: un `routes/web.php` que sirve la SPA y la salud de la CI
  es a la vez `route-closure` y `unmanaged-http`).

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
# un commit por paso; tras los de un ámbito, otra pasada:
larapack:realign diagnose
larapack:realign plan
larapack:realign apply --deterministic
# … las decisiones con el dueño, y al final:
larapack:verify --strict
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
  4 decisiones: los closures que no son el fallback de la SPA, los
  controladores y los modelos escritos a mano, y un archivo escrito en la
  ruta de uno generado.

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

**Cada paquete es su propio ámbito.** Cuando la raíz no tiene contrato ni
manifiesto, cada paquete local (`packages/*` y los repositorios `path`) es un
ámbito aunque tampoco tenga nada de LaraPack, igual que la raíz. Así, de punta
a punta:

- `diagnose` lista y resume cada ámbito (`app (núcleo)` y cada paquete por su
  nombre de Composer), y el código sin gestionar de un paquete es de su
  ámbito, aunque la raíz cargue ese paquete en su `autoload`.
- `plan` da a cada ámbito su `no-contract@<ruta>`, sus `unmanaged-http@<ruta>`
  y `unmanaged-models@<ruta>`; cada `controller-shape` de un paquete lleva el
  ámbito del paquete y espera sólo a las decisiones de ese paquete.
- `status`, `brief` y `done` agrupan por ámbito, y `--package` acota a uno.
- `infer no-contract@.` infiere **sólo la raíz**; cada paquete con su
  `no-contract@packages/<x>` (varios ids en una orden).

En un proyecto que sí usa LaraPack, un paquete sin contrato ni manifiesto no
es ámbito (un paquete de soporte no es una decisión): su código lo mide la
raíz, y sus `controller-shape` llevan igualmente la ruta del paquete.

**Recorrido recomendado:**

```bash
larapack:realign diagnose                        # segundos: no hay nada que regenerar
larapack:realign plan
larapack:realign infer no-contract@. no-contract@packages/billing --dry-run   # el informe, en el resultado
larapack:realign infer no-contract@. no-contract@packages/billing             # un laraimport.inferred.json por ámbito
# leer .larapack/realign/infer/<ámbito>.json: decisiones, not_inferred, lost
# decidir, con el dueño, ámbito por ámbito:
larapack:realign decide no-contract@. infer --note="…"
#   o dejarlo fuera:
larapack:realign decide no-contract@. keep --note="…"
larapack:realign except no-contract@. --option reason="Se queda fuera de LaraPack"
# … y lo mismo con no-contract@packages/billing, etc.
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
barata contra la deriva, y no obliga a adoptar nada. Los pasos
`controller-shape` se hacen igual (son guiados, con su playbook), cada
decisión `no-contract`, `unmanaged-http`, `unmanaged-models` y
`route-closure` se decide `keep`/`except` con su excepción aprobada, y al
final `verify --strict` sólo dice `empty-manifest`: no hay manifiesto porque
el proyecto no usa LaraPack. `larapack:realign done` sale con 0 y lo lista en
`declared`, fuera por decisión ([verify.md](verify.md#cuándo-se-termina)).

**Cuidado con:** la interfaz y la autorización no se infieren (adoptar y
regenerar el front daría pantallas sin campos; un endpoint de modelo nace con
una política que niega); `table_prefix` se deduce y hay que revisarlo; los
modelos a mano tienen lógica (scopes, `boot`) que el contrato no describe y
que un `import --force` reemplazaría. Todo en
[infer.md](infer.md#limitaciones-y-riesgos).

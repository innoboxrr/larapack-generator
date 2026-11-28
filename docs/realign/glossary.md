# Glosario

Parte de [`larapack:realign`](README.md). Cada término de la guía, definido
**una sola vez**. Las demás páginas los usan sin volver a explicarlos; cada
entrada dice dónde está el detalle. Entre paréntesis, cómo aparece en las
órdenes, en el JSON o en el código.

## Índice

- [El proyecto y sus piezas](#el-proyecto-y-sus-piezas)
- [El diagnóstico](#el-diagnóstico)
- [El plan](#el-plan)
- [Quién hace qué](#quién-hace-qué)
- [Dejar algo como está](#dejar-algo-como-está)
- [Las pruebas](#las-pruebas)
- [Terminar](#terminar)

## El proyecto y sus piezas

| Término | Qué es |
|---|---|
| **Contrato** (`laraimport.json`) | El archivo que declara la arquitectura del proyecto: modelos, columnas, relaciones, endpoints. LaraPack genera el código desde él. Lo que puede declarar: [contract.md](contract.md). |
| **Manifiesto** (`.larapack/manifest.json`) | La lista de cada archivo que LaraPack escribió, con su plantilla y el hash de su contenido. Con él se sabe si alguien editó un archivo. «Que el manifiesto diga la verdad» es que sus hashes sean los de lo que hay en disco. |
| **Ámbito** (`scope`, `--package`) | Una unidad con su propio contrato y su propio manifiesto: la **raíz** del proyecto (el núcleo, `app`, ruta `.`) y cada **paquete local** (`packages/*` y los repositorios `path` de Composer). Cada ámbito se diagnostica, se planifica y se termina por separado. `--package` acota una orden a uno, por su directorio, su ruta, su nombre de Composer o `app` ([diagnose.md](diagnose.md#qué-hace)). |
| **Lo regenerado** (la caché, `.larapack/realign/cache/`) | Lo que generaría **hoy** el contrato de un ámbito con la versión actual del generador, escrito aparte —nunca encima del proyecto— para comparar. No se edita ni se versiona; se rehace sola. Está en `.larapack/realign/cache/<ámbito>/<clave>/`. |
| **Hueco** | El sitio de un archivo generado hecho para escribir a mano: un archivo entero (un trait del modelo, una política, una prueba) o un método (`rules()` de una request, `canView()` de un ManagedFilter). Editar dentro de un hueco es lo correcto; editar fuera es desviarse ([diagnose.md](diagnose.md#los-huecos)). |
| **Código sin gestionar** (`unmanaged`) | Controladores, archivos de rutas y modelos escritos a mano que ni el contrato ni el manifiesto conocen ([diagnose.md](diagnose.md#el-código-sin-gestionar)). |
| **Dueño** | La persona con autoridad sobre el proyecto. Sólo ella toma las decisiones, aprueba las excepciones y acepta lo que quedó sin probar. **Un agente nunca hace de dueño.** |

## El diagnóstico

| Término | Qué es |
|---|---|
| **Diagnóstico** (`diagnose`, `report.json`) | La comparación del proyecto con lo regenerado, archivo por archivo, más el código sin gestionar y los hallazgos de `verify`. No escribe código ([diagnose.md](diagnose.md)). |
| **Clase** de un archivo (`class`) | Cómo está un archivo generado respecto de lo regenerado: `identical`, `format_only`, `version_delta`, `hole_edited`, `edited_outside`, `missing`, `renamed`, `not_regenerated`, `migration_history`, `untracked` ([diagnose.md](diagnose.md#las-clases-de-archivo)). |
| **Cumple** (`conforming`) | Un archivo sin paso pendiente: igual a lo regenerado y registrado con su hash, editado sólo en sus huecos, o con una excepción aprobada. |
| **Hallazgo** (`finding`) | Lo que encuentra una comprobación de `larapack:verify`: un error, un aviso o un `info`, con su archivo y su mensaje. |
| **Comprobación** (`check` de verify, `--check`) | Cada cosa que mira `larapack:verify`: `controller-shape`, `route-closure`, `index-managed`… No es la acción `check` del realineamiento ([diagnose.md](diagnose.md#los-hallazgos-de-verify)). |
| **Categoría** (`category`) | A quién le toca cada cosa: `deterministic` (la herramienta), `guided` (una persona o un agente, con un playbook), `decision` (el dueño) u `out_of_scope` (nadie) ([README.md](README.md#las-cuatro-categorías)). |
| **Fuera de alcance** (`out_of_scope`) | Lo que no es de LaraPack o no se toca nunca: servicios, dominio, front propio, la historia de las migraciones. Se cuenta para que nadie piense que se olvidó. |
| **Viejo** (`stale`) | Un diagnóstico hecho antes de que cambiara el contrato o el manifiesto de un ámbito. `status` lo dice; se arregla con `diagnose` y `plan`. |

## El plan

| Término | Qué es |
|---|---|
| **Plan** (`plan`, `plan.json`) | El diagnóstico convertido en pasos ordenados. Guarda el estado, las decisiones y la historia de cada paso, y es reanudable por cualquiera ([plan.md](plan.md)). |
| **Paso** (`step`) | Lo que se resuelve junto: una categoría, un tipo, sus archivos, su riesgo y su comprobación de terminado. |
| **Id** de un paso | Su nombre estable: `<tipo>[:<sujeto>]@<ámbito>` (`adopt@.`, `edited-outside:Requests@packages/billing`) o `controller-shape@<archivo>`. Es lo que se pasa a `next`, `show`, `check`, `decide` y a las operaciones. |
| **Tipo** de paso (`type`) | Qué clase de trabajo es: `adopt`, `regenerate`, `controller-shape`, `route-closure`… ([plan.md](plan.md#los-tipos-de-paso)). |
| **Elemento** (`--target`) | Cada cosa dentro de un paso: un archivo, un closure, un hallazgo. Una decisión se puede tomar por elemento y una excepción se pide por elemento. |
| **Estado** (`status`) | Dónde está un paso: `pending`, `awaiting_decision`, `decided`, `blocked`, `untested` o `done` ([plan.md](plan.md#los-estados)). |
| **Bloqueado** (`blocked`, `depends_on`) | Un paso que espera a otro: lo guiado espera a que el manifiesto diga la verdad y a las decisiones que tocan sus archivos. |
| **Criterio de terminado** (`done_when`) | La comprobación concreta que dice si un paso está hecho. La corre `check`; hacer el paso no lo marca hecho ([plan.md](plan.md#check)). |
| **Pasada** | Una vuelta de `diagnose` → `plan` → `apply --deterministic`. Hace falta otra después de aplicar, de copiar lo regenerado, de cambiar el contrato y de los pasos guiados de un ámbito ([README.md](README.md#el-ciclo-completo)). |
| **Aplazar** (`defer`, `undefer`) | Dejar un paso para más tarde, con su razón. Sigue pendiente —el realineamiento no termina con pasos aplazados— y `next` lo salta ([plan.md](plan.md#defer-y-undefer)). |
| **Mientras** (`meanwhile`) | Lo que `next` propone hacer cuando el paso que toca es una decisión del dueño: el primer paso que no espera a nadie. |
| **Esperando** (`waiting`) | Lo que dice `next` cuando queda trabajo pero nada que se pueda hacer ahora: qué está aplazado, en manos del dueño o bloqueado. |

## Quién hace qué

| Término | Qué es |
|---|---|
| **Determinista** (`deterministic`) | Lo que se aplica con una orden y cuyo resultado se comprueba sin opinar. Lo que no reconoce con certeza lo salta (`skipped`), con su razón. |
| **Operación** | Una acción que escribe fuera del estado: `adopt`, `apply`, `infer` y `except`. Aceptan `--dry-run`, exigen el árbol git limpio e informan `fixed`, `skipped` y `unchanged`. |
| **Adoptar** (`adopt`) | Anotar en el manifiesto un archivo que **ya es** lo que generaría el contrato, sin cambiar su contenido. No arregla nada: sólo hace que el manifiesto diga la verdad ([adopt.md](adopt.md)). También es la opción de dos decisiones: registrar como editado un archivo escrito a mano en la ruta de uno generado (`untracked-edited`) y llevar un modelo escrito a mano al contrato (`unmanaged-models`). |
| **Regenerar** (`regenerate`) | Copiar de lo regenerado un archivo que nadie editó y quedó atrás. Nunca un archivo editado ni una migración ([apply.md](apply.md#regenerate)). |
| **Inferir** (`infer`) | Escribir un contrato **borrador** (`laraimport.inferred.json`) leyendo las migraciones, los modelos y las rutas que ya existen. No toca el código ni el contrato ([infer.md](infer.md)). |
| **Guiado** (`guided`) | Trabajo mecánico que no se automatiza con certeza y que hace una persona o un agente siguiendo un playbook. |
| **Playbook** | Las instrucciones de un tipo de trabajo, escritas una vez en el paquete. `next` las da rellenadas con los archivos y las órdenes del paso ([guided.md](guided.md)). |
| **Anexo** | Un playbook que se añade a otro cuando los hallazgos lo piden (la validación en el controlador, una request sin tipar). |
| **Decisión** (`decision`, `decide`) | Un paso con más de una respuesta legítima, que cambia el comportamiento o el alcance. Trae sus **opciones** con sus consecuencias; la toma el dueño con `decide`. Decidir no es hacer: después va lo que la opción pida ([plan.md](plan.md#decide)). |
| **Pasar al dueño** (`escalate`) | Convertir un paso guiado en una decisión, cuando hacerlo o no no le toca a quien sigue el plan ([plan.md](plan.md#escalate)). |
| **El documento para el dueño** (`brief`) | Todo lo que espera al dueño —decisiones, excepciones sin aprobar, pasos sin probar— en un solo documento, con la orden exacta de cada cosa ([owner.md](owner.md)). |

## Dejar algo como está

| Término | Qué es |
|---|---|
| **Excepción** | Dejar como está, a propósito, algo que LaraPack señala. Se **pide** (`larapack:realign except`, lo puede hacer un agente) y se **aprueba** (`larapack:hand-rolled approve`, sólo el dueño). Pedida y sin aprobar no cubre nada ([exceptions.md](exceptions.md)). |
| **`#[HandRolled]`** | El atributo que marca un controlador o una acción que no puede delegar en su request. Es la solicitud de una excepción escrita en el código; la aprueba el dueño igual. |
| **Objetivo** (`target`) | El nombre exacto de lo que cubre una excepción: `realign:<tipo>[/<comprobación>]:<archivo>[#<sujeto>]` ([exceptions.md](exceptions.md#el-objetivo-qué-cubre-exactamente)). |
| **Huella** (`fingerprint`) | El hash de lo que cubre una excepción cuando se aprobó. Si el código cambia, la huella ya no coincide y la aprobación **caduca** ([exceptions.md](exceptions.md#la-huella-cuándo-caduca)). |
| **Candado** (`.larapack/hand-rolled.lock`) | El archivo con las excepciones aprobadas. Sólo lo escribe `larapack:hand-rolled`; su diff es lo que revisa el dueño. |
| **Workflow de aprobación** | La corrida de GitHub Actions, lanzada a mano por una persona, con la que el dueño aprueba sin terminal ([exceptions.md](exceptions.md#aprobar-sin-terminal-el-workflow-de-aprobación)). |

## Las pruebas

| Término | Qué es |
|---|---|
| **Cambia comportamiento** (`tests.required`) | Un paso que mueve autorización, validación o rutas, y puede cambiar lo que responde el proyecto (un 422 que pasa a 403). Sólo queda hecho con su prueba ([guided.md](guided.md#sin-pruebas-cuando-no-se-pueden-correr-las-del-proyecto)). |
| **`--tested`** | La orden de pruebas del proyecto que se corrió y pasó, declarada al comprobar el paso. Es la evidencia que queda en el plan. |
| **La orden de pruebas configurada** (`.larapack/realign/config.json`) | La orden de pruebas del proyecto, escrita una vez por una persona; `check` la corre él mismo. |
| **Sin probar** (`untested`) | Un paso hecho cuya prueba nadie registró. Cuenta como hecho para seguir con el plan, y **no** para terminar. |
| **Aceptar sin probar** (`accept-untested`) | El dueño acepta un paso `untested` tal cual, con su nota. Queda a la vista en el plan y en `done`. |

## Terminar

| Término | Qué es |
|---|---|
| **Comprobar** (`check`) | Correr el criterio de terminado de un paso. Si pasa, lo marca hecho; si no, dice qué falta (`remaining`). |
| **Terminado** (`done`) | El criterio único de todo el realineamiento: `larapack:realign done` sale con 0. Diagnostica otra vez y falla mientras falte algo en cualquier ámbito (`missing`) ([verify.md](verify.md#cuándo-se-termina)). |
| **`verify --strict`** | `larapack:verify` contando los avisos como fallos. Es una de las cosas que mira `done`, y la puerta de la rama protegida; solo no basta para dar el realineamiento por terminado. |
| **Perfil** | De dónde parte un proyecto: con contrato y al día, con contrato y deuda de versiones, o sin contrato. Dice dónde se va el trabajo ([profiles.md](profiles.md)). |

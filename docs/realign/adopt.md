# Adoptar código existente: `larapack:realign adopt`

Parte de [`larapack:realign`](README.md). Que el manifiesto
(`.larapack/manifest.json`) diga la verdad sobre lo que hay en disco, **sin
cambiar el contenido de un solo archivo**. Es casi siempre el primer paso, y el
que más archivos mueve de «no cumple» a «cumple».

## Índice

- [Cuándo hace falta](#cuándo-hace-falta)
- [Correrla](#correrla)
- [Qué hace con cada archivo](#qué-hace-con-cada-archivo)
- [La versión en lo heredado](#la-versión-en-lo-heredado)
- [Adoptar código escrito a mano](#adoptar-código-escrito-a-mano)
- [Comprobarlo](#comprobarlo)
- [Riesgos](#riesgos)

## Cuándo hace falta

El manifiesto guarda el hash de cada archivo generado. Deja de decir la verdad
cuando:

- **El proyecto pasa Pint (u otro formateador) después de generar**: el hash
  guardado es el de antes del formato, y `verify` ve todo como `customised`.
  Es el caso más común: en un proyecto 7.x ensayado, 1 914 archivos.
- **Una migración se renombró** a mano (otra fecha en el nombre).
- **Un modelo se retiró del contrato** y sus archivos se borraron, pero el
  manifiesto los sigue esperando.
- **Hay un archivo escrito a mano en la ruta de uno generado**, que el
  manifiesto no conoce.

Mientras el manifiesto no dice la verdad, `verify` no puede distinguir lo
editado de lo que sólo cambió de formato, y `import --force` puede tratar como
editado lo que no lo está (o al revés). Por eso todo lo guiado de un ámbito
espera a su `adopt`.

## Correrla

```bash
larapack:realign adopt adopt@. --dry-run      # qué anotaría
larapack:realign adopt adopt@.                # lo anota
larapack:realign adopt                        # todos sus pasos pendientes (y las decisiones `adopt` tomadas)
larapack:realign adopt --package=billing      # sólo los de un ámbito
larapack:realign apply --deterministic        # también hace los de adopt, en su orden, con los demás deterministas
```

Lo común a las operaciones que escriben: sin `--dry-run` exige el árbol git
limpio (o `--allow-dirty`), informa `fixed`/`skipped`/`unchanged` con la razón
de cada uno, termina con error si saltó algo y anota en la historia de cada
paso lo que hizo. **No da el paso por hecho**: eso es `check`.

## Qué hace con cada archivo

**Nunca cambia el contenido de un archivo**: sólo `.larapack/manifest.json`
del ámbito, que se escribe una vez, al final, de forma atómica. Antes de anotar
cada archivo lo vuelve a comparar con lo regenerado, con la misma clasificación
del diagnóstico: si alguien lo editó después de diagnosticar, lo salta
diciéndolo.

| Paso | Qué hace con cada archivo | Cuándo lo salta |
|---|---|---|
| `adopt` | Igual a lo regenerado (`identical`, `format_only`). Si el manifiesto lo conoce, vuelve a anotar su hash y la versión del generador, **conservando su estado** (lo generado sigue siendo `generated`). Si no lo conoce, lo registra con `state: adopted`, con el modelo, el namespace y el stub de lo regenerado. | Ya no es igual a lo regenerado. Lo regenerado no lo registra. |
| `relink` | Renombrado: mueve el registro a la ruta nueva con su stub y su hash. | Ya no se reconoce como renombrado (dos candidatos, o el original volvió). |
| `prune` | Borrado y el contrato no lo volvería a generar (`retired`, `not_regenerated`, `superseded`): lo olvida. Si es de un modelo que ya no está en el contrato, lo que le queda en disco pasa a quien lo genera hoy (el módulo compartido de la interfaz) o, si no lo genera nadie (una vista que se quedó), a lo que no es de ninguna entidad —sigue registrado y vigilado—, y el modelo se olvida: si no, `verify` lo vería como una entidad a la que le falta todo. | Vuelve a estar en disco. El contrato lo sigue pidiendo: eso es la decisión `missing`. |
| `untracked-edited` (si se decidió `adopt`) | Escrito a mano en la ruta de uno generado: lo registra con `state: adopted` y **el hash de lo regenerado**. Queda personalizado: `verify` lo sigue (deriva, forma de controlador) y `--force` no lo sobrescribe jamás. | Falta decidir, o se decidió otra cosa. Ya no es de esa clase. |

## La versión en lo heredado

En cada ámbito que toca, `adopt` anota además la versión actual del generador
en los archivos de un manifiesto v1 que siguen siendo, **sin editar** (su hash
es el del manifiesto), idénticos o iguales salvo espacios a lo regenerado. Su
hash no cambia. Desde ahí, si una versión posterior cambia la plantilla, el
diagnóstico lo distingue de un cambio del contrato. Lo que sólo es igual
pasándolo por Pint, o está editado en sus huecos, se queda sin versión: no se
afirma lo que no se comprobó. Sale en el informe como una línea con
`stamped` y `file: null`.

## Adoptar código escrito a mano

`adopt` sólo adopta lo que **ya es** lo que el contrato generaría. Lo escrito a
mano que hace otra cosa no se adopta en silencio:

- **En la ruta de un archivo generado** (`untracked` distinto de lo
  regenerado): es la decisión `untracked-edited`. Con la opción `adopt`, `adopt`
  lo registra como editado (abajo, el riesgo); con `except`, se deja fuera con
  una [excepción](exceptions.md).
- **En otra ruta** (controladores, rutas y modelos sin gestionar): primero hay
  que declararlo en el contrato. `larapack:realign infer` prepara un borrador
  ([infer.md](infer.md)); después de declararlo e importarlo, lo que el
  import deja igual a lo que había sale como `adopt` en el siguiente
  diagnóstico.

## Comprobarlo

```bash
larapack:realign check adopt@.
```

Pasa cuando cada archivo del paso, comparado otra vez, es `identical` o
`format_only` y está registrado con su hash actual. Para `prune`, cuando ya no
está ni en el manifiesto ni en disco (`absent`) o se reconoce con otra ruta;
para `relink`, cuando ya no sale ni como renombrado ni como borrado; para
`untracked-edited`, cuando ya no tiene paso pendiente.

## Riesgos

**Bajos**: sólo cambia el manifiesto, y se deshace con git
(`git restore .larapack/manifest.json`).

- **Adoptar un `format_only` marcado `comments: true`** deja que un
  `import --force` posterior reemplace esos comentarios por los de la
  plantilla. Si importan, adóptalo como editado: añade un cambio en un hueco, o
  decídelo como `untracked-edited`.
- **Adoptar como editado algo escrito a mano** (`untracked-edited` → `adopt`)
  hace que `--force` no lo regenere nunca: si después se quiere lo generado,
  hay que borrarlo y volver a importar.
- **`prune` de un modelo retirado** deja de vigilar lo que el contrato ya no
  genera. Si en realidad el modelo tenía que seguir, el arreglo no es un
  `prune`: es volver a declararlo en el contrato.
- **La versión anotada en lo heredado** es una afirmación: «esto es lo que
  genera la versión N». Sólo se anota donde se comprobó byte a byte o salvo
  espacios.

Lo que no cubre: `adopt` no arregla nada. Un archivo igual a lo regenerado ya
cumple; lo que no es igual sigue sin cumplir después de `adopt` y es de
[apply](apply.md) o de un [paso guiado](guided.md).

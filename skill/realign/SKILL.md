---
name: larapack-realign
description: Realinea con su contrato (laraimport.json) un proyecto Laravel que usa —o quiere usar— innoboxrr/larapack-generator y se desvió, sin reconstruirlo. Úsalo cuando haya un realineamiento en curso (.larapack/realign/plan.json), cuando pidan realinear, adoptar código existente, saltar de versión de LaraPack, bajar los hallazgos de larapack:verify o inferir un laraimport desde el código.
---

# Realinear con LaraPack

Llevar un proyecto de vuelta a su contrato **sin reconstruirlo**. Lo
determinista lo hace la herramienta; lo mecánico lo haces tú siguiendo un
playbook del paquete, con una comprobación concreta como criterio de terminado;
lo que es decisión se lo presentas a quien tenga autoridad; lo que no es de
LaraPack no se toca.

No necesitas un prompt del proyecto ni contexto previo: el estado vive en
`.larapack/realign/` y el paquete trae las instrucciones de cada paso. La guía
completa está en el paquete: `vendor/innoboxrr/larapack-generator/docs/realign/README.md`.

```
php artisan larapack:realign <acción>              # en una aplicación Laravel
php vendor/bin/builder larapack:realign <acción>   # en un paquete
```

## Empieza siempre aquí

```bash
larapack:realign status --format=json
```

- Sin plan, o con `stale` no vacío: `larapack:realign diagnose` y
  `larapack:realign plan`.
- Con plan: sigue la receta.

## La receta

1. `larapack:realign next --format=json`. Si `done` es true, `diagnose` y
   `plan` otra vez para confirmar; si sigue sin nada, comprueba el
   cumplimiento con `larapack:verify --strict --format=json` y se terminó.
2. Según `step.category`:
   - **`deterministic`**: ejecuta `how.command`, primero con `--dry-run` y
     después sin él. Lo que salte (`skipped`, con su razón) lo haces a mano
     siguiendo `how.fallback.instructions`. `larapack:realign apply
     --deterministic` hace todos los deterministas de una vez; después,
     `diagnose` y `plan` otra vez.
   - **`guided`**: sigue `how.instructions` al pie de la letra. Corre las
     pruebas del proyecto **antes** de tocar nada y **después**.
   - **`decision`**: **no decidas tú.** Presenta `how.options`, con sus
     consecuencias y, si lo trae, el playbook de la decisión
     (`how.instructions`), a quien tenga autoridad. Cuando decida:
     `larapack:realign decide <id> <opción> --note="…"`. Después, `next` dice
     qué pide la opción. Si es dejarlo como está, pide la excepción
     (`larapack:realign except <id> --option reason="…"`) y avisa al dueño:
     **no la apruebes tú**.
3. `larapack:realign check <id> --format=json`. Si `done` es false, resuelve
   `remaining` y vuelve a comprobar. Sólo `check` da un paso por hecho.
4. Un commit por paso, con `.larapack/realign/plan.json`. Vuelve al 1.

Para preparar una decisión sobre código sin contrato (`no-contract`,
`unmanaged-models`, `unmanaged-http`) sin decidir nada:
`larapack:realign infer <id>` escribe un borrador
(`laraimport.inferred.json`) y su informe, y no toca el código ni el contrato.

## Lo que nunca haces

- **Decidir o aprobar por el dueño**: ni `larapack:realign decide` sin que te
  lo pidan, ni `larapack:hand-rolled approve`/`revoke`, ni editar
  `.larapack/hand-rolled.lock` o `.larapack/realign/plan.json` a mano, ni
  añadir `#[HandRolled]` para que verify pase.
- **Escribir o reescribir una migración**, ni tocar la base.
- **Cambiar un nombre de ruta o una URI** que el contrato no declare: el front
  y los clientes dependen de ellos.
- **`--allow-dirty`** para saltarte un árbol sucio que no es tuyo: comitea o
  pregunta.
- **Tocar lo `out_of_scope`**: servicios, dominio, front propio, la historia de
  las migraciones.
- **Dar por hecho un paso** porque lo hiciste: lo dice `check`.

## Qué puede romper un paso

- Mover validación y autorización a un FormRequest hace que una petición sin
  permiso reciba **403 en vez de 422**: fíjalo con una prueba antes.
- Regenerar o arreglar cambia comportamiento (un listado que respeta
  `managed`, un Resource que deja de servir `appends`): las pruebas lo dicen.
- Un endpoint de modelo nuevo nace con una política que **niega**.

## Dónde está cada cosa

En `vendor/innoboxrr/larapack-generator/docs/realign/`:

| Guía | Para |
|---|---|
| `README.md` | El modelo mental, las categorías, la seguridad y la receta. |
| `diagnose.md` | Leer el informe y cada clasificación. |
| `plan.md` | Los pasos, `next`, `check`, `decide` y los formatos JSON. |
| `adopt.md`, `apply.md` | Lo determinista. |
| `guided.md` | Los playbooks (`larapack:realign playbook` los lista). |
| `exceptions.md` | Dejar algo como está, con la aprobación del dueño. |
| `infer.md`, `contract.md` | Un proyecto sin contrato, y lo que el contrato puede declarar. |
| `verify.md` | Cuándo se termina y qué garantiza. |
| `risks.md` | Limitaciones y riesgos de cada operación. |
| `profiles.md` | El recorrido según de dónde parte el proyecto. |

`larapack:realign help <acción>` explica cada acción con su uso, lo que
escribe y su guía.

Este archivo lo reescribe `larapack:skill`. Las reglas propias del proyecto van
en otro archivo.

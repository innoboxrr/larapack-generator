# Decidir: ¿se adoptan estos modelos en el contrato?

Paso `{{id}}` · ámbito {{scope}}

Esto es una **decisión**. Si eres un agente, prepara la información y
preséntala; no decidas.

## Los modelos

{{files}}

## Cómo pensarla

Adoptar un modelo es declararlo en `laraimport.json`, y eso **genera para él
CRUD, política, rutas, requests, recurso, pruebas y, con `--react`/`--vue`, su
interfaz**. Para cada modelo pregúntate:

1. **¿Debe poder tocarse por la API?** Un registro inmutable (una bitácora,
   un asiento contable, una versión firmada de un documento) no debe tener
   `update` ni `delete` por HTTP. Se puede adoptar con `"immutable": true` y
   `"routes": {"only": [...]}`, pero la decisión es de alguien que conozca el
   dominio. Ante la duda, no se adopta.
2. **¿Es de Laravel o de un paquete de terceros?** El `User` de Laravel ya
   tiene su forma (y 9.3.1 lo reconoce); un modelo de un paquete instalado no
   es de este proyecto.
3. **¿Lo vas a mantener desde el contrato?** Adoptar sin la intención de
   declarar sus cambios futuros en `laraimport.json` deja un modelo que el
   import vuelve a escribir y nadie revisa.
4. **¿Su tabla se puede describir?** `larapack:realign infer {{id}}
   --dry-run` lee sus migraciones y dice lo que no puede inferir (defaults que
   son expresiones, columnas que pone un bucle, llaves en ciclo). Lo de
   interfaz (formularios, `enum` con etiquetas) siempre se completa a mano.
5. **Las llaves foráneas.** Si sus migraciones hacen `cascade`, declara
   `on_delete`/`on_update` con eso mismo: un contrato que calla pide
   `restrict` (ver `larapack:realign playbook decide-foreign-keys`).

## Las opciones

- **adopt** — `larapack:realign infer {{id}}` escribe el borrador; revisarlo,
  pasarlo a `laraimport.json`, validar, importar en simulación e importar. Las
  migraciones existentes **no se reescriben nunca**.
- **keep** — Se pide una excepción por modelo:

      larapack:realign except {{id}} --option reason="Registro inmutable; no se expone por la API."

  Vale mientras el archivo no cambie. La aprueba el dueño.

      larapack:realign decide {{id}} <adopt|keep> --note="…"

## Riesgo

{{risk}}

Está hecho cuando {{done_when}}

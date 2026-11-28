## LaraPack

La arquitectura de este proyecto la genera LaraPack. Antes de crear o cambiar
un modelo, un endpoint, una request, una política, una migración o una vista,
lee `{{skill}}` y sigue su flujo.

- **La arquitectura se declara, no se escribe.** Lo que falta se declara en
  `laraimport.json`, se valida con `larapack:validate` y se genera con
  `larapack:import`. Tu código va sólo en los huecos que deja el generador.
- **Un endpoint que no es CRUD también se declara**: en `endpoints` del modelo,
  o en `controllers` si no tiene modelo. Antes de escribir un controlador.
- **El controlador sólo hace `return $request->handle();`**, también el que se
  escribe a mano: un asistente, un escritorio, una ruta pública, un webhook, un
  canje de tokens. La autorización, la validación, las consultas y la respuesta
  van en un `FormRequest` (`authorize()`, `rules()`, `handle()`), que delega en
  la operación del modelo o en una clase de soporte.
- **`#[HandRolled]` lo decide y lo aprueba el dueño del proyecto**, no tú: no lo
  añadas para que `verify` pase, no edites `.larapack/hand-rolled.lock` y no
  corras `larapack:hand-rolled approve` ni `revoke`.
- **Antes de dar una tarea por terminada**, `larapack:validate --format=json` y
  `larapack:verify --format=json`, los dos con código de salida 0.
- **Si hay un realineamiento en curso** (`.larapack/realign/plan.json`) o te
  piden llevar el proyecto de vuelta al contrato, empieza por
  `larapack:realign status` y sigue la guía del paquete
  (`vendor/innoboxrr/larapack-generator/docs/realign/README.md`): las
  decisiones y las excepciones las toma y aprueba el dueño, no tú. Está
  terminado sólo cuando `larapack:realign done --format=json` sale con 0.

```
php artisan larapack:<comando>               # dentro de una aplicación Laravel
php vendor/bin/builder larapack:<comando>    # en un paquete
```

Si el proyecto tiene los hooks de LaraPack (`larapack:skill --hooks`), una
edición que rompe el contrato vuelve con el motivo, y el hook de git frena el
commit: corrígela, no la rodees. No uses `--no-verify` ni cambies
`core.hooksPath`; la CI comprueba lo mismo.

Este bloque lo reescribe `larapack:skill`. Las reglas propias del proyecto van
fuera de los marcadores.

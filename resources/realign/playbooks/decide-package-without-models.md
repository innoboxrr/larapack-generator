# Decidir: un contrato que no valida (el paquete sin modelos)

Paso `{{id}}` · ámbito {{scope}}

Esto es una **decisión**. Si eres un agente, prepara la información y
preséntala; no decidas.

## Qué pasa

El `laraimport.json` de este ámbito no valida con esta versión del
generador. Sin un contrato válido no se puede regenerar la referencia y nada
del ámbito se puede comparar. Míralo con:

    larapack:validate --package={{package}} --format=json

## El caso conocido: un paquete de integración sin modelos

En school-management, 12 paquetes de integración (pasarelas de pago, PAC,
TPV) declaran `"models": []`. No tienen entidades: son un servicio, sus
rutas, su configuración. El esquema de 9.x pide al menos un modelo.

Pregúntate:

1. **¿El paquete genera algo con LaraPack?** Si su `laraimport.json` sólo
   existe por costumbre y nada de lo que tiene lo generó LaraPack, el contrato
   no aporta nada.
2. **¿Tiene controladores o rutas?** Si sí, se pueden declarar como
   `controllers[]` (que no necesita modelos) y entonces el contrato vuelve a
   tener algo que generar.
3. **¿Lo vigila algo más?** Sin contrato, `verify` sigue comprobando la forma
   de sus controladores (`controller-shape`) y sus closures de rutas.

## Las opciones

- **fix** — Corregir el JSON hasta que `larapack:validate` pase: declarar sus
  controladores en `controllers[]`, o lo que el error pida. El código no se
  toca.
- **drop** — Retirar el `laraimport.json` del ámbito (y su
  `.larapack/manifest.json` si no registra nada útil). El ámbito deja de estar
  gestionado por LaraPack; el diagnóstico lo verá entonces como `no-contract`,
  y ahí se puede pedir la excepción «fuera de LaraPack».

      larapack:realign decide {{id}} <fix|drop> --note="…"

## Riesgo

{{risk}}

Está hecho cuando {{done_when}}

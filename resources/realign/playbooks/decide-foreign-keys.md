# Decidir: ¿qué pasa al borrar? (`cascade` frente a `restrict`)

Paso `{{id}}` · ámbito {{scope}}

Esto es una **decisión** sobre el comportamiento de la base de datos de
producción, y la toma alguien que conozca el dominio. Si eres un agente,
prepara la información y preséntala; **no cambies una llave** sin la decisión.

## El contexto

Hasta la 7.x, LaraPack escribía las llaves foráneas con
`->onUpdate('cascade')->onDelete('cascade')`. Desde la 8.0, un contrato que
no declara `on_delete`/`on_update` pide `restrict`. Un proyecto que salta de
versión tiene sus migraciones con `cascade` y un contrato que, callando, pide
otra cosa: en school-management salen 89 alteraciones vacías en la raíz, en
expediente 33 (158 `onDelete('cascade')` en sus migraciones).

**Fijar lo que hay no es decidir.** El paso determinista
`pin-foreign-keys` (si tu versión lo trae) escribe en el contrato
`on_delete`/`on_update` con lo que ya hacen las migraciones: la base no cambia
y el ruido desaparece. Hazlo primero. Lo que queda es esta decisión: ¿quieres
**cambiar** lo que pasa al borrar?

## Cómo pensarla, llave por llave

- **`cascade`**: borrar el padre borra los hijos. Bien para lo que no tiene
  sentido sin su padre (las líneas de un pedido, las metas de un registro).
  Peligroso para datos con obligación de conservarse: en un expediente
  clínico (NOM-004), que borrar un paciente borre sus notas es justo lo que
  pregunta una auditoría.
- **`restrict`**: no se puede borrar el padre mientras tenga hijos. Lo
  prudente para datos que se conservan; obliga a la aplicación a decidir
  antes de borrar (archivar, soft delete).
- **`set null`**: el hijo se queda huérfano. Sólo si la columna es nullable y
  el hijo tiene sentido sin padre.

Para cada llave: ¿hay borrado físico de ese padre en la aplicación (o sólo
soft delete)? ¿Hay datos con obligación legal de conservarse debajo? ¿Hay
pruebas que borren?

## Cómo se aplica lo decidido

1. Se declara en el contrato (`"on_delete": "restrict"` en el prop de la
   llave). El import genera entonces una **alteración nueva**; las
   migraciones existentes **no se reescriben nunca**.
2. En una base con datos, `restrict` falla si ya hay hijos que lo violarían
   al borrar: no al migrar, sino al primer borrado. Prueba los borrados de la
   aplicación antes de desplegar.
3. Un cambio por commit, con la nota de la decisión.

## Riesgo

Alto: cambia lo que se borra en producción. Reversible con otra migración,
pero no lo que ya se haya borrado en cascada.

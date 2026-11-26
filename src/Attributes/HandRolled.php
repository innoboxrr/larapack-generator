<?php

namespace Innoboxrr\LarapackGenerator\Attributes;

use Attribute;

/**
 * Marca un controlador, o una acción, que de verdad no puede ser
 * `return $request->handle();`.
 *
 * Hay pocos casos así —un webhook que tiene que leer el cuerpo en bruto para
 * comprobar la firma, el canje de un token OAuth que responde con los errores
 * de OAuth y no con los de Laravel— y no se distinguen mirando el código. Por
 * eso no se adivinan: se declaran, con la razón escrita, y `larapack:verify`
 * los lista como aviso en cada ejecución (con --strict, falla). Una excepción
 * que se ve se revisa; una que nadie ve se convierte en la costumbre.
 *
 * La decide el dueño del proyecto, no quien escribe el controlador: un agente
 * no lo añade para que `verify` pase.
 *
 * La razón es obligatoria y tiene que ser una cadena literal, legible sin
 * ejecutar nada. Sin ella el atributo no excusa nada y `verify` lo cuenta como
 * error: un escape sin motivo es justo la deriva que se quiere impedir, sólo
 * que con permiso.
 *
 * PHP no carga la clase de un atributo hasta que alguien lo instancia por
 * reflexión, así que puede usarse aunque el generador esté sólo en
 * require-dev.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class HandRolled
{
    public function __construct(public string $reason) {}
}

<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

/**
 * Cómo está un archivo respecto de lo que generaría el contrato con la
 * versión actual del generador. Lo decide el diagnóstico regenerando el
 * proyecto en otro sitio y comparando archivo por archivo
 * (FileComparison, Diagnosis).
 *
 * La categoría (Category) no es una función de la clase: un `missing` de un
 * modelo retirado del contrato se arregla solo (se olvida en el manifiesto) y
 * uno de un modelo vivo es una decisión. Por eso cada entrada del informe
 * lleva las dos.
 */
final class FileClass
{
    /** Byte a byte lo que generaría hoy el contrato. */
    public const IDENTICAL = 'identical';

    /** Igual salvo formato: espacios, comentarios o lo que cambia Pint. */
    public const FORMAT_ONLY = 'format_only';

    /** Nadie lo editó (su hash es el del manifiesto) y aun así difiere: la plantilla o el contrato cambiaron desde que se generó. */
    public const VERSION_DELTA = 'version_delta';

    /** Editado, pero sólo dentro de los huecos (Holes): es código legítimo. */
    public const HOLE_EDITED = 'hole_edited';

    /** Editado fuera de los huecos. */
    public const EDITED_OUTSIDE = 'edited_outside';

    /** No está en disco y el contrato lo produciría (o el manifiesto lo conoce). */
    public const MISSING = 'missing';

    /** El manifiesto lo conoce con otra ruta: el mismo archivo, renombrado. */
    public const RENAMED = 'renamed';

    /** Lo registró el generador, pero `import` no lo regenera (proveedores, configuración, una alteración). */
    public const NOT_REGENERATED = 'not_regenerated';

    /** Una migración generada que ya no es la que produciría hoy el contrato. Las migraciones no se reescriben nunca. */
    public const MIGRATION_HISTORY = 'migration_history';

    /** En la ruta que generaría el contrato hay un archivo que el manifiesto no conoce. */
    public const UNTRACKED = 'untracked';

    /** Ni en disco, ni en el manifiesto, ni lo produce el contrato. Sólo aparece al comprobar un paso. */
    public const ABSENT = 'absent';

    public const ALL = [
        self::IDENTICAL, self::FORMAT_ONLY, self::VERSION_DELTA, self::HOLE_EDITED, self::EDITED_OUTSIDE,
        self::MISSING, self::RENAMED, self::NOT_REGENERATED, self::MIGRATION_HISTORY, self::UNTRACKED, self::ABSENT,
    ];

    /**
     * Lo que cumple el contrato tal cual: no genera ningún paso.
     */
    public const CONFORMING = [self::IDENTICAL, self::FORMAT_ONLY, self::HOLE_EDITED];

    public const DESCRIPTIONS = [
        self::IDENTICAL => 'idéntico a lo que generaría hoy el contrato',
        self::FORMAT_ONLY => 'igual salvo formato (espacios, comentarios, Pint)',
        self::VERSION_DELTA => 'sin editar, pero la plantilla o el contrato cambiaron desde que se generó',
        self::HOLE_EDITED => 'editado sólo dentro de los huecos',
        self::EDITED_OUTSIDE => 'editado fuera de los huecos',
        self::MISSING => 'falta en disco',
        self::RENAMED => 'renombrado: el manifiesto lo conoce con otra ruta',
        self::NOT_REGENERATED => 'lo registró el generador, pero import no lo regenera',
        self::MIGRATION_HISTORY => 'migración generada que ya no coincide con el contrato (no se reescribe)',
        self::UNTRACKED => 'en la ruta de un archivo generado, sin registrar en el manifiesto',
        self::ABSENT => 'ni en disco, ni en el manifiesto, ni en el contrato',
    ];
}

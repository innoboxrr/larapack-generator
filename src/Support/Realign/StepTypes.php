<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

/**
 * El catálogo de tipos de paso del plan: qué categoría es cada uno, qué
 * riesgo tiene y por qué, si se puede deshacer, y cómo se ejecuta.
 *
 * Es el punto de extensión del plan. Un tipo nuevo —una corrección
 * determinista más, un trabajo guiado con su playbook— se añade aquí con
 * todos sus datos, y Planner lo usa sin cambiar nada más. Una prueba
 * (RealignCatalogTest) comprueba que cada tipo guiado tenga su playbook o
 * caiga, declarado, en el genérico, y que cada tipo tenga riesgo y razón.
 *
 * Cómo se ejecuta cada categoría:
 *
 *  - deterministic: `run` es la orden; `{id}` se cambia por el id del paso y
 *    `{package}` por el paquete. Las que empiezan por `larapack:realign` las
 *    ejecuta una operación del registro (Operations); las demás son comandos
 *    que ya existían (`larapack:fix`).
 *  - guided: `playbook` es el nombre en resources/realign/playbooks/.
 *  - decision: `options`, cada una con lo que pasa al elegirla y, si la
 *    lleva, la orden que la aplica.
 *
 * @phpstan-type Option array{key: string, label: string, consequences: string, run?: string}
 * @phpstan-type StepType array{category: string, title: string, risk: string, why: string, reversible: bool, run?: string, playbook?: string, options?: list<Option>}
 */
final class StepTypes
{
    public const LOW = 'low';

    public const MEDIUM = 'medium';

    public const HIGH = 'high';

    /**
     * @var array<string, StepType>
     */
    public const TYPES = [
        // ── Lo que desbloquea: el manifiesto tiene que decir la verdad antes
        // de que nada más se pueda juzgar.
        'relink' => [
            'category' => Category::DETERMINISTIC,
            'title' => 'Volver a enlazar archivos renombrados',
            'risk' => self::LOW,
            'why' => 'Sólo cambia el manifiesto: el archivo es el mismo, con otro nombre (una migración renombrada a mano).',
            'reversible' => true,
            'run' => 'larapack:realign adopt {id}',
        ],
        'prune' => [
            'category' => Category::DETERMINISTIC,
            'title' => 'Olvidar en el manifiesto lo que ya no existe',
            'risk' => self::LOW,
            'why' => 'Sólo cambia el manifiesto: el archivo ya no está y el contrato no lo volvería a generar (un modelo retirado, una alteración borrada).',
            'reversible' => true,
            'run' => 'larapack:realign adopt {id}',
        ],
        'adopt' => [
            'category' => Category::DETERMINISTIC,
            'title' => 'Adoptar lo que ya es lo que generaría el contrato',
            'risk' => self::LOW,
            'why' => 'Sólo cambia el manifiesto: el archivo es idéntico, o igual salvo formato, a lo regenerado. Hasta hacerlo, verify lo ve editado y --force nunca lo vuelve a tocar.',
            'reversible' => true,
            'run' => 'larapack:realign adopt {id}',
        ],
        'regenerate' => [
            'category' => Category::DETERMINISTIC,
            'title' => 'Regenerar lo que nadie editó y quedó atrás',
            'risk' => self::MEDIUM,
            'why' => 'Reescribe archivos que el manifiesto dice que nadie editó, con lo que genera hoy el contrato: el salto de plantilla entre versiones o un contrato que va por delante. Cambia comportamiento si la plantilla nueva lo cambia. Nunca toca migraciones.',
            'reversible' => true,
            'run' => 'larapack:realign apply {id}',
        ],
        'fix' => [
            'category' => Category::DETERMINISTIC,
            'title' => 'Corregir la deuda que larapack:fix sabe pagar',
            'risk' => self::MEDIUM,
            'why' => 'Reescribe sólo lo que tiene la forma generada y salta lo demás diciendo por qué. index-managed acota el listado (cambia lo que ve el administrador si canView() no lo prevé); resource-appends puede dejar de servir accessors que pide el front.',
            'reversible' => true,
            'run' => 'larapack:fix {check} --package={package}',
        ],

        // ── Guiado: lo hace un agente con su playbook y verify como juez.
        'controller-shape' => [
            'category' => Category::GUIDED,
            'title' => 'Llevar la lógica del controlador a su FormRequest',
            'risk' => self::MEDIUM,
            'why' => 'Mueve código: la validación pasa antes que la autorización o al revés (un 403 que llega antes que un 422), y una respuesta puede cambiar de forma. Las pruebas del paquete tienen que pasar antes y después.',
            'reversible' => true,
            'playbook' => 'controller-shape',
        ],
        'controller-concern' => [
            'category' => Category::DETERMINISTIC,
            'title' => 'Sacar los traits de Http/Controllers/Concerns que sólo usan requests',
            'risk' => self::LOW,
            'why' => 'Mueve el trait a Http/Requests/Concerns y reescribe por tokens cada nombre que lo apunta; el comportamiento no cambia. Lo que lo nombra de una forma que no se reescribe con certeza (una cadena, un `use` agrupado) se salta y se hace a mano con el playbook. El que usa un controlador no se mueve: es controller-shape.',
            'reversible' => true,
            'run' => 'larapack:realign apply {id}',
            'playbook' => 'controller-concern',
        ],
        'endpoint-tests' => [
            'category' => Category::DETERMINISTIC,
            'title' => 'Completar las pruebas de endpoints de versiones anteriores',
            'risk' => self::LOW,
            'why' => 'Añade al test existente las pruebas que trae hoy la plantilla, sin tocar lo escrito a mano; no cambia código de producción. Pueden fallar con razón: si una acción responde sin sesión o no pregunta a la política, la prueba lo destapa.',
            'reversible' => true,
            'run' => 'larapack:realign apply {id}',
            'playbook' => 'endpoint-tests',
        ],
        'edited-outside' => [
            'category' => Category::GUIDED,
            'title' => 'Devolver a los huecos lo editado fuera de ellos',
            'risk' => self::MEDIUM,
            'why' => 'Mueve lógica de un archivo generado (un authorize(), un handle(), una lista del modelo) a su hueco o al contrato, y después el archivo se regenera. Si la lógica no cabe en ningún hueco, es una decisión: declararlo como excepción.',
            'reversible' => true,
            'playbook' => 'edited-outside',
        ],
        'declared-routes' => [
            'category' => Category::GUIDED,
            'title' => 'Cuadrar rutas y requests con lo declarado',
            'risk' => self::MEDIUM,
            'why' => 'Una request sin ruta o una ruta sin request: se declara en el contrato o se retira. Cambiar un nombre de ruta rompe al front que la llama; aquí no se cambia ninguno que el contrato no declare.',
            'reversible' => true,
            'playbook' => 'declared-routes',
        ],
        'security' => [
            'category' => Category::GUIDED,
            'title' => 'Cerrar una escritura o una exposición que el contrato prohíbe',
            'risk' => self::HIGH,
            'why' => 'Es un agujero: un modelo inmutable que se escribe o una columna secreta que sale en la respuesta. Arreglarlo cambia lo que ve o puede hacer el cliente, a propósito.',
            'reversible' => true,
            'playbook' => 'security',
        ],
        'model-lists' => [
            'category' => Category::DETERMINISTIC,
            'title' => 'Poner las listas del modelo al día con el contrato',
            'risk' => self::MEDIUM,
            'why' => 'Las listas del modelo (fillable, casts, export_cols) no dicen lo que el contrato. Se añade lo que falta, lo mismo que hace `import --force` con un modelo editado; nunca se quita ni se cambia nada. Cambia qué se asigna y cómo se convierte una columna. Lo que no se localiza con certeza se salta con la orden manual.',
            'reversible' => true,
            'run' => 'larapack:realign apply {id}',
            'playbook' => 'model-lists',
        ],
        'pin-foreign-keys' => [
            'category' => Category::DETERMINISTIC,
            'title' => 'Fijar en el contrato lo que hacen hoy las llaves foráneas',
            'risk' => self::LOW,
            'why' => 'Sólo cambia laraimport.json: escribe on_delete/on_update con lo que ya hacen las migraciones (la cascada de 7.x) donde el contrato calla, para que un salto de versión no cambie lo que se borra en producción. La base no cambia. Cambiar la acción de verdad es otra decisión (declarar otra y migrar).',
            'reversible' => true,
            'run' => 'larapack:realign apply {id}',
        ],
        'verify-other' => [
            'category' => Category::GUIDED,
            'title' => 'Resolver un hallazgo de verify',
            'risk' => self::MEDIUM,
            'why' => 'Una comprobación de verify que el plan no conoce con nombre propio: se resuelve leyendo su mensaje.',
            'reversible' => true,
            'playbook' => 'generic',
        ],

        // ── Decisiones: las toma una persona.
        'hand-rolled' => [
            'category' => Category::DECISION,
            'title' => 'Aprobar o deshacer las excepciones #[HandRolled] sin aprobar',
            'risk' => self::MEDIUM,
            'why' => 'Una excepción aprobada deja de vigilarse mientras no cambie. Aprobarla sin leerla es aceptar lógica en el controlador para siempre.',
            'reversible' => true,
            'options' => [
                ['key' => 'approve', 'label' => 'Aprobarlas', 'consequences' => 'Quedan en .larapack/hand-rolled.lock con su razón y su huella; si el código cambia, vuelven a avisar.', 'run' => 'larapack:hand-rolled approve --all'],
                ['key' => 'refactor', 'label' => 'Llevarlas a su request', 'consequences' => 'Se quita el atributo y la lógica va a la request, como cualquier controlador (el playbook controller-shape).'],
            ],
        ],
        'route-closure' => [
            'category' => Category::DECISION,
            'title' => 'Rutas con closure que no son el fallback de la SPA',
            'risk' => self::MEDIUM,
            'why' => 'Una ruta con closure no pasa por una request: ni autorización ni validación generadas. A veces es lo correcto (un OPTIONS de CORS, la raíz de la SPA según el Host).',
            'reversible' => true,
            'options' => [
                ['key' => 'except', 'label' => 'Declararlas excepción', 'consequences' => 'Quedan aprobadas con su razón y dejan de contar como deuda.', 'run' => 'larapack:realign except {id}'],
                ['key' => 'refactor', 'label' => 'Llevarlas a un controlador con su request', 'consequences' => 'Se escriben controlador y request (o se declaran en controllers[]) conservando la URI y el nombre de la ruta.'],
            ],
        ],
        'inconsistent-entity' => [
            'category' => Category::DECISION,
            'title' => 'Modelos con una forma distinta a la de los demás',
            'risk' => self::LOW,
            'why' => 'Le falta a un modelo algo que todos los de su forma tienen (el User de Laravel sin factory de LaraPack). Puede ser legítimo.',
            'reversible' => true,
            'options' => [
                ['key' => 'except', 'label' => 'Aceptar la diferencia', 'consequences' => 'Se declara como excepción y deja de avisar.', 'run' => 'larapack:realign except {id}'],
                ['key' => 'complete', 'label' => 'Completarlo', 'consequences' => 'Se genera lo que falta desde el contrato (larapack:import).'],
            ],
        ],
        'missing' => [
            'category' => Category::DECISION,
            'title' => 'Archivos generados que se borraron',
            'risk' => self::MEDIUM,
            'why' => 'El contrato los sigue produciendo y alguien los borró: o se quieren de vuelta o el contrato tiene que dejar de pedirlos.',
            'reversible' => true,
            'options' => [
                ['key' => 'restore', 'label' => 'Restaurarlos desde el contrato', 'consequences' => 'Se vuelven a generar tal cual los produce hoy el contrato.', 'run' => 'larapack:realign apply {id}'],
                ['key' => 'forget', 'label' => 'Dejarlos borrados', 'consequences' => 'Se olvidan en el manifiesto y se declara la excepción; si lo pide el contrato, hay que retirarlo del contrato (p. ej. `routes.only`).', 'run' => 'larapack:realign except {id}'],
            ],
        ],
        'untracked-edited' => [
            'category' => Category::DECISION,
            'title' => 'Archivos escritos a mano donde el contrato generaría otros',
            'risk' => self::MEDIUM,
            'why' => 'En la ruta de un archivo generado hay uno distinto que el manifiesto no conoce. Adoptarlo como editado lo protege de --force; regenerarlo perdería lo escrito.',
            'reversible' => true,
            'options' => [
                ['key' => 'adopt', 'label' => 'Adoptarlos como editados', 'consequences' => 'Quedan en el manifiesto con el hash de lo regenerado: verify los verá personalizados y --force no los toca.', 'run' => 'larapack:realign adopt {id}'],
                ['key' => 'except', 'label' => 'Dejarlos fuera de LaraPack', 'consequences' => 'Se declaran excepción: el contrato no debería generarlos (se retira del contrato lo que los produce).', 'run' => 'larapack:realign except {id}'],
            ],
        ],
        'unmanaged-http' => [
            'category' => Category::DECISION,
            'title' => 'Controladores y rutas escritos a mano',
            'risk' => self::HIGH,
            'why' => 'Declararlos en el contrato (endpoints o controllers[]) trae pruebas y autorización generadas, pero no puede cambiar ni un nombre de ruta ni una URI que use el front. Dejarlos fuera es legítimo si delegan en su request (controller-shape lo comprueba).',
            'reversible' => true,
            'options' => [
                ['key' => 'declare', 'label' => 'Declararlos en el contrato', 'consequences' => 'Se infieren endpoints/controllers[] conservando nombre de ruta y URI, y se adoptan.', 'run' => 'larapack:realign infer {id}'],
                ['key' => 'keep', 'label' => 'Dejarlos como código propio', 'consequences' => 'Siguen bajo controller-shape; no reciben pruebas generadas.', 'run' => 'larapack:realign except {id}'],
            ],
        ],
        'unmanaged-models' => [
            'category' => Category::DECISION,
            'title' => 'Modelos escritos a mano, fuera del contrato',
            'risk' => self::HIGH,
            'why' => 'Adoptarlos genera CRUD, políticas y rutas para ellos: a veces es justo lo que no se quiere (un registro inmutable que nadie debe tocar por la API).',
            'reversible' => true,
            'options' => [
                ['key' => 'adopt', 'label' => 'Inferirlos al contrato y adoptarlos', 'consequences' => 'Se infieren sus columnas de las migraciones y del modelo; lo de interfaz (formularios, enum) se completa a mano.', 'run' => 'larapack:realign infer {id}'],
                ['key' => 'keep', 'label' => 'Dejarlos fuera', 'consequences' => 'Siguen siendo código propio; LaraPack no les genera nada.', 'run' => 'larapack:realign except {id}'],
            ],
        ],
        'no-contract' => [
            'category' => Category::DECISION,
            'title' => 'Sin contrato: no hay con qué comparar',
            'risk' => self::HIGH,
            'why' => 'Sin laraimport.json no hay referencia: se puede inferir uno del código (migraciones y modelos) o dejar el ámbito fuera de LaraPack.',
            'reversible' => true,
            'options' => [
                ['key' => 'infer', 'label' => 'Inferir el contrato', 'consequences' => 'Se escribe un laraimport.json desde el código; lo que no se puede inferir (formularios, enum, decisiones de rutas) se marca para completar.', 'run' => 'larapack:realign infer {id}'],
                ['key' => 'keep', 'label' => 'Dejarlo fuera de LaraPack', 'consequences' => 'Sólo se vigila la forma de los controladores (controller-shape).', 'run' => 'larapack:realign except {id}'],
            ],
        ],
        'contract-invalid' => [
            'category' => Category::DECISION,
            'title' => 'El contrato no es válido con esta versión',
            'risk' => self::LOW,
            'why' => 'Sin un contrato válido no se puede regenerar la referencia, y nada del ámbito se puede comparar. Un paquete de integración sin modelos (`models: []`) es el caso conocido.',
            'reversible' => true,
            'options' => [
                ['key' => 'fix', 'label' => 'Corregir el laraimport', 'consequences' => 'Se corrige el JSON hasta que larapack:validate pase; el código no se toca.'],
                ['key' => 'drop', 'label' => 'Retirar el laraimport del ámbito', 'consequences' => 'El ámbito deja de estar gestionado por LaraPack (su código lo sigue midiendo verify).'],
            ],
        ],
    ];

    /**
     * El orden de las fases del plan: lo que desbloquea, lo determinista,
     * lo guiado y las decisiones. Dentro de cada fase, el orden de esta
     * lista.
     */
    public const ORDER = [
        'relink', 'prune', 'adopt', 'regenerate', 'fix',
        'controller-concern', 'controller-shape', 'declared-routes', 'security', 'edited-outside', 'model-lists', 'endpoint-tests', 'verify-other', 'pin-foreign-keys',
        'contract-invalid', 'no-contract', 'missing', 'untracked-edited', 'hand-rolled', 'route-closure', 'inconsistent-entity', 'unmanaged-http', 'unmanaged-models',
    ];

    /**
     * Qué paso pide cada comprobación de verify. `customised`,
     * `missing-file` y `empty-manifest` no: la clasificación de archivos dice
     * más que ellas. Una comprobación que no esté aquí va a `verify-other`.
     */
    public const CHECKS = [
        'customised' => null,
        'missing-file' => null,
        'empty-manifest' => null,
        'index-managed' => 'fix',
        'resource-appends' => 'fix',
        'relation-missing' => 'fix',
        'route-closure' => 'route-closure',
        'controller-shape' => 'controller-shape',
        'request-handle' => 'controller-shape',
        'controller-concern' => 'controller-concern',
        'hand-rolled' => 'hand-rolled',
        'endpoint-tests-outdated' => 'endpoint-tests',
        'route-not-declared' => 'declared-routes',
        'endpoint-missing' => 'declared-routes',
        'route-prefix' => 'declared-routes',
        'immutable-write' => 'security',
        'secret-exposed' => 'security',
        'model-lists-outdated' => 'model-lists',
        'inconsistent-entity' => 'inconsistent-entity',
    ];

    /**
     * @return StepType
     */
    public static function get(string $type): array
    {
        return self::TYPES[$type] ?? self::TYPES['verify-other'];
    }

    public static function exists(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /**
     * El tipo de paso de un hallazgo de verify, o null si no pide paso.
     *
     * @param  array{check: string, message: string}  $finding
     */
    public static function forFinding(array $finding): ?string
    {
        if (! array_key_exists($finding['check'], self::CHECKS)) {
            return 'verify-other';
        }

        $type = self::CHECKS[$finding['check']];

        // El único closure que `larapack:fix` sabe corregir lo dice su
        // propio mensaje (SpaFallback, el mismo que decide el arreglo).
        if ($type === 'route-closure' && str_contains($finding['message'], 'larapack:fix route-closure')) {
            return 'fix';
        }

        // Un trait de Http/Controllers que usa un controlador no se arregla
        // moviéndolo: su lógica va a la request (ControllerShape lo dice en
        // el mensaje; el aviso, cuando sólo lo usan requests, sí se mueve).
        if ($type === 'controller-concern' && ! str_contains($finding['message'], 'ningún controlador lo usa')) {
            return 'controller-shape';
        }

        return $type;
    }

    public static function position(string $type): int
    {
        $position = array_search($type, self::ORDER, true);

        return $position === false ? count(self::ORDER) : $position;
    }
}

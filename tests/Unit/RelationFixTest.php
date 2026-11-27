<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\RelationFix;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Lo que RelationFix::add() hace con un trait de relaciones, en las formas que
 * puede tener además de la del generador.
 */
final class RelationFixTest extends TestCase
{
    /**
     * @return array{model: string, trait: string, name: string, method: string, use: string|null}
     */
    private static function relation(string $name = 'clinic', ?string $use = 'use App\\Models\\Clinic;'): array
    {
        return [
            'model' => 'Location',
            'trait' => 'app/Models/Traits/Relations/LocationRelations.php',
            'name' => $name,
            'method' => "    public function {$name}()\n    {\n        return \$this->belongsTo(Clinic::class);\n    }",
            'use' => $use,
        ];
    }

    public function test_un_trait_vacio_sin_imports_recibe_el_metodo_y_el_use_tras_el_namespace(): void
    {
        $code = "<?php\n\nnamespace App\\Models\\Traits\\Relations;\n\ntrait LocationRelations\n{\n}\n";

        $this->assertSame(
            "<?php\n\nnamespace App\\Models\\Traits\\Relations;\n\nuse App\\Models\\Clinic;\n\ntrait LocationRelations\n{\n    public function clinic()\n    {\n        return \$this->belongsTo(Clinic::class);\n    }\n}\n",
            RelationFix::add($code, self::relation())
        );
    }

    public function test_una_relacion_al_propio_modelo_no_importa_nada(): void
    {
        $code = "<?php\n\nnamespace App\\Models\\Traits\\Relations;\n\ntrait LocationRelations\n{\n}\n";

        $this->assertStringNotContainsString('use ', RelationFix::add($code, self::relation('parent', null)));
    }

    public function test_si_ya_importa_la_misma_clase_no_la_repite(): void
    {
        $code = "<?php\n\nnamespace App\\Models\\Traits\\Relations;\n\nuse App\\Models\\Clinic;\n\ntrait LocationRelations\n{\n}\n";

        $this->assertSame(1, substr_count(RelationFix::add($code, self::relation()), 'use App\\Models\\Clinic;'));
    }

    public function test_un_metodo_con_ese_nombre_se_salta(): void
    {
        $code = "<?php\n\nnamespace App;\n\ntrait LocationRelations\n{\n    public function Clinic() {}\n}\n";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ya tiene un método');

        RelationFix::add($code, self::relation());
    }

    public function test_un_archivo_que_no_es_un_trait_se_salta(): void
    {
        $this->expectException(RuntimeException::class);

        RelationFix::add("<?php\n\nnamespace App;\n\nclass LocationRelations\n{\n}\n", self::relation());
    }

    public function test_un_alias_con_el_mismo_nombre_corto_se_salta(): void
    {
        $code = "<?php\n\nnamespace App;\n\nuse Other\\Place as Clinic;\n\ntrait LocationRelations\n{\n}\n";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Other\\Place');

        RelationFix::add($code, self::relation());
    }
}

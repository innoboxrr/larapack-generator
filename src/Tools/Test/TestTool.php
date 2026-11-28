<?php

namespace Innoboxrr\LarapackGenerator\Tools\Test;

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Support\Files;
use Innoboxrr\LarapackGenerator\Support\Generation;
use Innoboxrr\LarapackGenerator\Tools\ModelArtifact;
use Innoboxrr\LarapackGenerator\Tools\Tool;

class TestTool extends Tool implements ModelArtifact
{
    protected string $testPath = '';

    protected string $testTemplatePath = '';

    protected string $testCasePath = '';

    protected string $testUnitPath = '';

    private function setTestPath(): static
    {

        $this->testPath = get_path('tests/Feature/Models');

        return $this;

    }

    private function setTestCasePath(): static
    {

        $this->testCasePath = get_path('tests');

        return $this;

    }

    private function setTestUnitPath(): static
    {

        $this->testUnitPath = get_path('tests/Unit');

        return $this;

    }

    private function setTestTemplatePath(): static
    {

        $this->testTemplatePath = stubs_path('Test');

        return $this;

    }

    public function create(string $ModelName): bool
    {

        return $this->init($ModelName)
            ->setTestPath()
            ->setTestCasePath()
            ->setTestUnitPath()
            ->setTestTemplatePath()
            ->createTestCaseClass()
            ->createTestUserClass()
            ->createPhpUnitXmlFile()
            ->addTestNamespaceToComposerJson()
            ->createFeatureTest();

    }

    /**
     * Lo que un proyecto necesita para tener tests antes de su primer modelo.
     */
    public function createScaffold(): static
    {

        $this->init('')
            ->setTestCasePath()
            ->setTestTemplatePath()
            ->createTestCaseClass()
            ->createTestUserClass()
            ->createPhpUnitXmlFile()
            ->addTestNamespaceToComposerJson();

        return $this;

    }

    /**
     * Copia una plantilla de test si el destino no existe, respetando la
     * simulacion y dejando constancia: antes se copiaba en silencio, tambien con
     * --dry-run, y el informe no la mencionaba.
     */
    private function copyOnce(string $template, string $destination): bool
    {

        if (file_exists($destination)) {

            return false;

        }

        if (Generation::isDryRun()) {

            Generation::record('create', $destination, $template);

            return false;

        }

        if (! Files::copy($template, $destination)) {

            throw MakerException::copyFailed($template, $destination);
        }

        Generation::record('create', $destination, $template);

        return true;

    }

    private function createTestCaseClass(): static
    {

        $testCaseFile = $this->testCasePath.'/TestCase.php';

        // Una aplicacion trae el suyo; si falta, el de Laravel. El de un paquete
        // arranca Testbench y autentica con tests/User.php, que en una
        // aplicacion no se genera.
        $templateName = (app_dir_name() == 'src') ?
            '/TestCaseTemplate.txt' :
            '/TestCaseProjectTemplate.txt';

        if ($this->copyOnce($this->testTemplatePath.$templateName, $testCaseFile)) {

            $this->replaceData($testCaseFile);

        }

        return $this;

    }

    /**
     * El usuario con que se autentican los tests de un paquete. Una aplicacion
     * ya tiene el suyo; un paquete no conoce el de quien lo instale.
     */
    private function createTestUserClass(): static
    {

        $userFile = $this->testCasePath.'/User.php';

        if (app_dir_name() == 'src' && $this->copyOnce($this->testTemplatePath.'/TestUserTemplate.txt', $userFile)) {

            $this->replaceData($userFile);

        }

        return $this;

    }

    private function createPhpUnitXmlFile(): static
    {

        $phpunitFile = root_path().'/phpunit.xml';

        // Un phpunit.xml.dist ya es la configuracion del proyecto; crear ademas
        // un phpunit.xml la taparia en silencio para quien lo tenga.
        if (file_exists(root_path().'/phpunit.xml.dist')) {

            return $this;

        }

        if (! file_exists($phpunitFile)) {

            $templateFile = $this->testTemplatePath.'/PhpunitTemplate.txt';

            if ($this->copyOnce($templateFile, $phpunitFile)) {

                // El directorio de cobertura depende de si es paquete (src) o proyecto (app).
                Files::write($phpunitFile, str_replace(
                    '__SOURCE_DIR__',
                    app_dir_name(),
                    self::contents($phpunitFile)
                ));

            }

        }

        return $this;

    }

    private function addTestNamespaceToComposerJson(): static
    {
        // Sin `autoload-dev.psr-4`, la sección se reescribía entera y se
        // perdía lo que tuviera (un classmap, unos files): ahora se añade.
        $this->addPsr4ToComposerJson('autoload-dev', 'Tests\\', 'tests/');

        return $this;
    }

    private function createFeatureTest(): bool
    {

        $testFile = $this->testPath.'/'.$this->PascalCaseModelName.'EndpointsTest.php';

        if (! file_exists($testFile)) {

            $templateName = (app_dir_name() == 'src') ?
                '/TestPackageTemplate.txt' :
                '/TestProjectTemplate.txt';

            $templateFile = $this->testTemplatePath.$templateName;

            if (Generation::isDryRun()) {

                Generation::record('create', $testFile, $templateFile);

                // Como generate(): en una simulación, true es «se escribiría».
                return true;

            }

            if (Files::copy($templateFile, $testFile)) {

                $this->applyBlocks($testFile);

                $this->replaceData($testFile);

            } else {

                throw MakerException::copyFailed($templateFile, $testFile);
            }

        } else {

            return false;

        }

        return true;

    }

    public function remove(string $ModelName): bool
    {

        $this->init($ModelName)
            ->setTestPath();

        $path = $this->testPath.'/'.$this->PascalCaseModelName.'EndpointsTest.php';

        return (file_exists($path)) ? $this->dropFile($path) : false;

    }
}

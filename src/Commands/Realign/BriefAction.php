<?php

namespace Innoboxrr\LarapackGenerator\Commands\Realign;

use Innoboxrr\LarapackGenerator\Support\Realign\Diagnosis;
use Innoboxrr\LarapackGenerator\Support\Realign\OwnerBrief;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * `larapack:realign brief`: lo que espera al dueño, en un documento.
 */
final class BriefAction extends Action
{
    /**
     * Lo que espera al dueño, en un documento: lo escribe en
     * .larapack/realign/brief.{md,json} y lo imprime (OwnerBrief).
     *
     * @param  list<string>  $targets
     */
    public function run(array $targets): int
    {
        if ($targets !== []) {
            return $this->fail('brief no recibe ids: junta todo lo que espera al dueño (acótalo con --package).');
        }

        $state = $this->state();
        $plan = $state->requirePlan();
        $report = $state->report();
        $paths = $this->scopePaths();
        $brief = new OwnerBrief($this->root);
        $document = $brief->build($plan, Planner::stepsIn($plan, $paths), Planner::byId($plan), $paths, Diagnosis::stale($report, $this->root), $report['generated_at'] ?? null);
        $brief->save($document);

        if ($this->wantsJson()) {
            $this->json($document);

            return Command::SUCCESS;
        }

        $this->output->writeln(OutputFormatter::escape(OwnerBrief::markdown($document)));
        $this->output->writeln('  Escrito en '.$document['files']['markdown'].' (para el dueño) y '.$document['files']['json'].' (para un agente). Se retoma con: larapack:realign status');

        return Command::SUCCESS;
    }
}

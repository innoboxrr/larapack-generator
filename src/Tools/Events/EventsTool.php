<?php

namespace Innoboxrr\LarapackGenerator\Tools\Events;

use Innoboxrr\LarapackGenerator\Tools\Tool;

class EventsTool extends Tool
{
    private string $eventsPath = '';

    private string $eventsTemplatePath = '';

    private string $mainEventsPath = '';

    /**
     * Cada evento con sus oyentes, en el orden en que se generan.
     *
     * @var array<string, list<string>>
     */
    private array $events = [
        'CreateEvent' => [
            'DefaultOperation',
        ],
        'DeleteEvent' => [
            'DefaultOperation',
        ],
        'ExportEvent' => [
            'DefaultOperation',
            'SendExportNotification',
        ],
        'ForceDeleteEvent' => [
            'DefaultOperation',
        ],
        'RestoreEvent' => [
            'DefaultOperation',
        ],
        'UpdateEvent' => [
            'DefaultOperation',
        ],
    ];

    private function setEventsPath(): static
    {
        $this->eventsPath = get_path(app_dir_name().'/Http/Events');

        return $this;
    }

    private function setEventsTemplatePath(): static
    {
        $this->eventsTemplatePath = stubs_path('Events');

        return $this;
    }

    private function setMainEventsPath(): static
    {
        $path = $this->eventsPath.'/'.$this->PascalCaseModelName;
        if (! file_exists($path)) {
            mkdir($path, 0777, true);
        }
        $this->mainEventsPath = $path;

        return $this;
    }

    private function setModelEventsPath(): static
    {
        $path = $this->mainEventsPath.'/'.'Events';
        if (! file_exists($path)) {
            mkdir($path, 0777, true);
        }

        return $this;
    }

    private function setModelListenersPath(): static
    {
        $path = $this->mainEventsPath.'/'.'Listeners';
        if (! file_exists($path)) {
            mkdir($path, 0777, true);
        }

        return $this;
    }

    public function create(string $ModelName): void
    {
        $this->init($ModelName)
            ->setEventsPath()
            ->setEventsTemplatePath()
            ->setMainEventsPath()
            ->setModelEventsPath()
            ->setModelListenersPath()
            ->createEvents();
    }

    private function createEvents(): void
    {
        foreach ($this->events as $event => $listeners) {
            if (! $this->declares(lcfirst(substr($event, 0, -strlen('Event'))))) {
                continue;
            }

            $this->createEvent($event);
            foreach ($listeners as $listener) {
                $this->createListener($event, $listener);
            }
        }
    }

    private function createEvent(string $eventName): bool
    {
        $eventFile = $this->mainEventsPath.'/Events/'.$eventName.'.php';

        return $this->generate($this->eventsTemplatePath.'/Events/'.$eventName.'.txt', $eventFile);
    }

    private function createListener(string $eventName, string $listenerName): bool
    {
        $listenerPath = $this->createEventListenerDir($eventName);
        $listenerFile = $listenerPath.'/'.$listenerName.'.php';

        return $this->generate($this->eventsTemplatePath.'/Listeners/'.$eventName.'/'.$listenerName.'.txt', $listenerFile);
    }

    private function createEventListenerDir(string $eventName): string
    {
        $path = $this->mainEventsPath.'/Listeners/'.$eventName;
        if (! file_exists($path)) {
            mkdir($path, 0777, true);
        }

        return $path;
    }

    public function remove(string $ModelName): bool
    {
        $this->init($ModelName)
            ->setEventsPath();
        $path = $this->eventsPath.'/'.$this->PascalCaseModelName;

        return $this->dropModelDir($path);
    }
}

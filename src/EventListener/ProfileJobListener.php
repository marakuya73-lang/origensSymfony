<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Artefact\ProfileJobRunner;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::TERMINATE)]
final class ProfileJobListener
{
    public function __construct(private ProfileJobRunner $runner)
    {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $id = $event->getRequest()->attributes->get('origens_profile_job');
        if (!is_string($id) || $id === '') {
            return;
        }
        ignore_user_abort(true);
        set_time_limit(0);
        $this->runner->detachWebRequest();
        $this->runner->executeId($id);
    }
}

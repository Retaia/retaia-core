<?php

namespace App;

use App\Startup\StorageMarkerStartupValidator;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        parent::boot();

        // Boot-time marker validation is disabled in test env to keep isolated fixtures lightweight.
        if ($this->environment === 'test') {
            return;
        }

        /** @var StorageMarkerStartupValidator $validator */
        $validator = $this->getContainer()->get(StorageMarkerStartupValidator::class);
        $validator->validateStartup();
    }
}

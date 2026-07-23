<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\AppInfo;

use OCA\DiskMap\Usage\FilecacheUsageSource;
use OCA\DiskMap\Usage\IUsageSource;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Bootstrap for the DiskMap app.
 *
 * Controllers, LayoutDetector and FilecacheUsageSource all rely on
 * constructor autowiring — their dependencies are concrete classes or core
 * Nextcloud interfaces, which the DI container resolves on its own. The one
 * exception is IUsageSource: the container can't guess which implementation
 * to hand out for an interface, so that binding needs to be explicit (plan
 * §8 — this is also the seam a future IRootFolder-based implementation would
 * swap in through).
 */
class Application extends App implements IBootstrap {
    public const APP_ID = 'diskmap';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerServiceAlias(IUsageSource::class, FilecacheUsageSource::class);
    }

    public function boot(IBootContext $context): void {
    }
}

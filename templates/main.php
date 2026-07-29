<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

script('diskmap', 'diskmap-main');
style('diskmap', 'main');
?>

<div id="diskmap">
    <!-- The Vue 3 app is mounted here. Nextcloud's own layout.user.php
         already wraps this in <div id="content" class="app-diskmap">
         (see core/templates/layout.user.php) — adding another #content
         wrapper here duplicated the id and doubled the header-height
         offset applied by Nextcloud's #content CSS rule. -->
</div>

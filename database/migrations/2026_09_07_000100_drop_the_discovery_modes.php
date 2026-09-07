<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The discovery modes are gone: the dial page, its engine, its admin resource
 * and the two tables only they wrote. Removed 2026-09-07 — see
 * docs/features/discovery-modes.md for what it was and why it went.
 *
 * `discovery_reactions` held "not for me" presses from the dial and fed
 * nothing else; `mode_profiles` held the per-mode weights the admin screen
 * edited. Neither is read by any surviving code, so there is nothing to
 * contract first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('discovery_reactions');
        Schema::dropIfExists('mode_profiles');
    }

    public function down(): void
    {
        // Forward-only, like every migration here. The tables' shape is in
        // 2026_08_08_000200_create_mode_profiles_table if it is ever wanted.
    }
};

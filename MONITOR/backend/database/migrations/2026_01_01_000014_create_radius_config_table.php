<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The RADIUS servers MONITOR talks to, editable from Administration Settings.
 *
 * Ported from GOWISER's own `radius_config` table — same columns, same meaning,
 * same two-server cap — so an operator configuring both systems is filling in
 * one form twice rather than learning two schemas. GOWISER's
 * RadiusServerResolver orders by id and calls them #1 and #2; MONITOR keeps that
 * convention and uses the same order for read failover.
 *
 * ── Why this moved out of config/mikrotik.php ─────────────────────────
 *
 * It used to live in the environment, on the argument that credentials able to
 * disconnect a region should require a deploy and appear in a diff. That is a
 * fair argument and it lost to a plainer one: the RADIUS endpoints move, the
 * people who move them do not have deploy access, and a setting that can only be
 * changed by an engineer is a setting that stays wrong for a week.
 *
 * What replaces the deploy gate:
 *
 *   - the password is encrypted at rest (App\Models\RadiusServer casts it), so a
 *     database dump does not hand over the routers;
 *   - it is never returned by the API — the controller sends a `has_password`
 *     flag instead, and an update that omits it keeps the stored one;
 *   - reads and writes need `action.radius.manage`, which is separate from every
 *     other settings grant;
 *   - every write is audited with the actor, and the payload is recorded without
 *     the password.
 *
 * The environment variables still work and are still read when this table is
 * empty, so an existing deployment keeps running until someone fills the form
 * in. See UserManagerClient::servers().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radius_config', function (Blueprint $table) {
            $table->id();

            // How to reach the REST API. Split rather than stored as one URL
            // because that is how GOWISER stores it and how the RouterOS admin
            // reads it off the device — "https", "10.0.0.2", "443".
            $table->string('ssl_type', 8)->default('https');
            $table->string('ip');
            $table->string('port', 8)->default('443');

            $table->string('username');
            // Encrypted by the model. Sized for the ciphertext, not the secret.
            $table->text('password');

            // What the screens call this server. Not a key: the key is derived
            // from the row's position, matching the primary/secondary failover
            // order the client has always used.
            $table->string('label')->nullable();

            // Excluded from failover without being deleted, so a server can be
            // taken out of rotation during maintenance and put back.
            $table->boolean('is_active')->default(true);

            $table->string('updated_by')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radius_config');
    }
};

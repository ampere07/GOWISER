<?php
/**
 * RouterOS API Client
 * Pure PHP socket-based MikroTik RouterOS API client.
 * Compatible with RouterOS 6.x and 7.x.
 */
class RouterosAPI {
    private mixed  $socket   = null;
    private bool   $connected = false;
    public  string $error    = '';
    public  int    $timeout  = 5;

    private string $host;
    private int    $port;

    public function __construct(string $host, int $port = 8728, int $timeout = 5) {
        $this->host    = $host;
        $this->port    = $port;
        $this->timeout = $timeout;
    }

    // ── Connection ────────────────────────────────────────────
    public function connect(string $username, string $password): bool {
        $this->error = '';
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);

        if (!$this->socket) {
            $this->error = "Cannot connect to {$this->host}:{$this->port} — {$errstr} ({$errno})";
            return false;
        }

        stream_set_timeout($this->socket, $this->timeout);
        stream_set_blocking($this->socket, true);

        // Modern RouterOS login (6.43+)
        $response = $this->rawCommand(['/login', '=name=' . $username, '=password=' . $password]);

        if ($this->checkDone($response)) {
            $this->connected = true;
            return true;
        }

        // Legacy challenge-response (older RouterOS)
        $challenge = $this->extractAttribute($response, 'ret');
        if ($challenge !== null) {
            $hash    = md5("\x00" . $password . pack('H*', $challenge));
            $response2 = $this->rawCommand(['/login', '=name=' . $username, '=response=00' . $hash]);
            if ($this->checkDone($response2)) {
                $this->connected = true;
                return true;
            }
        }

        $this->error   = 'Login failed: ' . ($this->extractAttribute($response, 'message') ?? 'Unknown error');
        $this->connected = false;
        fclose($this->socket);
        $this->socket = null;
        return false;
    }

    public function disconnect(): void {
        if ($this->socket) {
            try { $this->rawCommand(['/quit']); } catch (Exception $e) {}
            // socket may already be null if the router closed the connection during /quit response
            if ($this->socket) { fclose($this->socket); }
            $this->socket    = null;
            $this->connected = false;
        }
    }

    public function isConnected(): bool {
        return $this->connected && $this->socket !== null;
    }

    // ── Query ─────────────────────────────────────────────────
    /**
     * Execute a RouterOS API command.
     *
     * @param string $command  e.g. '/ip/address/print'
     * @param array  $params   e.g. ['=.id' => '*1', '=disabled' => 'yes']
     * @param array  $queries  e.g. ['?name=pppoe-user1']
     * @return array  Parsed rows (each row is key=>value associative)
     */
    public function query(string $command, array $params = [], array $queries = []): array {
        if (!$this->connected) {
            $this->error = 'Not connected';
            return [];
        }
        $sentence = [$command];
        foreach ($params as $key => $value) {
            $sentence[] = '=' . ltrim($key, '=') . '=' . $value;
        }
        foreach ($queries as $query) {
            $sentence[] = $query;
        }
        $raw = $this->rawCommand($sentence);
        return self::parseResponse($raw);
    }

    private static function normalizeUsername(string $value): string {
        return strtolower(trim($value));
    }

    private static function rowUsername(array $row, array $keys): string {
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
                return trim((string)$row[$key]);
            }
        }
        return '';
    }

    private static function userMatches(array $row, array $keys, string $username): bool {
        $rowUser = self::rowUsername($row, $keys);
        if ($rowUser === '') return false;
        return $rowUser === trim($username)
            || self::normalizeUsername($rowUser) === self::normalizeUsername($username);
    }

    /** Shortcut: /ppp/active/print */
    public function getPPPActive(): array {
        return $this->query('/ppp/active/print');
    }

    /** Shortcut: /ip/hotspot/active/print */
    public function getHotspotActive(): array {
        return $this->query('/ip/hotspot/active/print');
    }

    /** Shortcut: /system/resource/print */
    public function getSystemResource(): array {
        $rows = $this->query('/system/resource/print');
        return $rows[0] ?? [];
    }

    /** Shortcut: /system/identity/print */
    public function getIdentity(): string {
        $rows = $this->query('/system/identity/print');
        return $rows[0]['name'] ?? 'Unknown';
    }

    /** Add PPP secret */
    public function addPPPSecret(array $params): bool {
        if (!$this->connected) return false;
        $cmd = ['/ppp/secret/add'];
        foreach ($params as $k => $v) $cmd[] = '=' . $k . '=' . $v;
        $raw = $this->rawCommand($cmd);
        return $this->checkDone($raw);
    }

    /** Update an existing PPP secret by .id (password, profile, comment) */
    public function updatePPPSecret(string $id, string $password, string $profile, string $comment): bool {
        if (!$this->connected) return false;
        $raw = $this->rawCommand([
            '/ppp/secret/set',
            '=.id='      . $id,
            '=password=' . $password,
            '=profile='  . $profile,
            '=comment='  . $comment,
        ]);
        return $this->checkDone($raw);
    }

    /** Disable/Enable PPP secret */
    public function setPPPSecretDisabled(string $name, bool $disabled): bool {
        if (!$this->connected) return false;
        // Find ID first
        $rows = $this->query('/ppp/secret/print', [], ['?name=' . $name]);
        if (empty($rows[0]['.id'])) return false;
        $raw = $this->rawCommand([
            '/ppp/secret/set',
            '=.id=' . $rows[0]['.id'],
            '=disabled=' . ($disabled ? 'yes' : 'no'),
        ]);
        return $this->checkDone($raw);
    }

    /** Set comment on a PPP secret by name */
    public function setPPPSecretComment(string $name, string $comment): bool {
        if (!$this->connected) return false;
        $rows = $this->query('/ppp/secret/print', [], ['?name=' . $name]);
        if (empty($rows[0]['.id'])) return false;
        $raw = $this->rawCommand([
            '/ppp/secret/set',
            '=.id=' . $rows[0]['.id'],
            '=comment=' . $comment,
        ]);
        return $this->checkDone($raw);
    }

    /** Remove PPP secret by name */
    public function removePPPSecret(string $name): bool {
        if (!$this->connected) return false;
        $rows = $this->query('/ppp/secret/print', [], ['?name=' . $name]);
        if (empty($rows[0]['.id'])) return false;
        $raw = $this->rawCommand(['/ppp/secret/remove', '=.id=' . $rows[0]['.id']]);
        return $this->checkDone($raw);
    }

    /** Disconnect active PPP session */
    public function disconnectPPPSession(string $id): bool {
        if (!$this->connected) return false;
        $raw = $this->rawCommand(['/ppp/active/remove', '=.id=' . $id]);
        return $this->checkDone($raw);
    }

    /** Disconnect all active PPP sessions matching a username */
    public function disconnectPPPSessionsByName(string $name): int {
        if (!$this->connected) return 0;
        $rows = $this->query('/ppp/active/print', [], ['?name=' . $name]);
        $removed = 0;
        foreach ($rows as $row) {
            if (!empty($row['.id']) && $this->disconnectPPPSession($row['.id'])) {
                $removed++;
            }
        }
        return $removed;
    }

    /** Disconnect PPP sessions with a full-list fallback for manual operator actions. */
    public function disconnectPPPSessionsByNameRobust(string $name): int {
        $rows = $this->findPPPActiveSessionsByNameRobust($name);
        $removed = 0;
        foreach ($rows as $row) {
            if (empty($row['.id'])) continue;
            if ($this->disconnectPPPSession($row['.id'])) {
                $removed++;
            }
        }
        return $removed;
    }

    /** Find PPP active sessions with a full-list fallback for manual operator actions. */
    public function findPPPActiveSessionsByNameRobust(string $name): array {
        if (!$this->connected) return [];
        $name = trim($name);
        if ($name === '') return [];

        $rows = $this->query('/ppp/active/print', [], ['?name=' . $name]);
        $matches = array_values(array_filter(
            $rows,
            fn($row) => self::userMatches($row, ['name', 'user', 'username'], $name)
        ));
        if (empty($matches)) {
            $rows = $this->query('/ppp/active/print');
            $matches = array_values(array_filter(
                $rows,
                fn($row) => self::userMatches($row, ['name', 'user', 'username'], $name)
            ));
        }
        return $matches;
    }

    /** Disconnect active Hotspot session by username */
    public function disconnectHotspotSession(string $username): bool {
        if (!$this->connected) return false;
        $rows = $this->query('/ip/hotspot/active/print', [], ['?user=' . $username]);
        if (empty($rows[0]['.id'])) return false;
        $raw = $this->rawCommand(['/ip/hotspot/active/remove', '=.id=' . $rows[0]['.id']]);
        return $this->checkDone($raw);
    }

    /** Disconnect all active Hotspot sessions matching a username */
    public function disconnectHotspotSessionsByUser(string $username): int {
        if (!$this->connected) return 0;
        $rows = $this->query('/ip/hotspot/active/print', [], ['?user=' . $username]);
        $removed = 0;
        foreach ($rows as $row) {
            if (empty($row['.id'])) continue;
            $raw = $this->rawCommand(['/ip/hotspot/active/remove', '=.id=' . $row['.id']]);
            if ($this->checkDone($raw)) {
                $removed++;
            }
        }
        return $removed;
    }

    /** Disconnect Hotspot sessions with a full-list fallback for manual operator actions. */
    public function disconnectHotspotSessionsByUserRobust(string $username): int {
        $rows = $this->findHotspotActiveSessionsByUserRobust($username);
        $removed = 0;
        foreach ($rows as $row) {
            if (empty($row['.id'])) continue;
            $raw = $this->rawCommand(['/ip/hotspot/active/remove', '=.id=' . $row['.id']]);
            if ($this->checkDone($raw)) {
                $removed++;
            }
        }
        return $removed;
    }

    /** Find Hotspot active sessions with a full-list fallback for manual operator actions. */
    public function findHotspotActiveSessionsByUserRobust(string $username): array {
        if (!$this->connected) return [];
        $username = trim($username);
        if ($username === '') return [];

        $rows = $this->query('/ip/hotspot/active/print', [], ['?user=' . $username]);
        $matches = array_values(array_filter(
            $rows,
            fn($row) => self::userMatches($row, ['user', 'name', 'username', 'login', 'login-by'], $username)
        ));
        if (empty($matches)) {
            $rows = $this->query('/ip/hotspot/active/print');
            $matches = array_values(array_filter(
                $rows,
                fn($row) => self::userMatches($row, ['user', 'name', 'username', 'login', 'login-by'], $username)
            ));
        }
        return $matches;
    }

    /** Add Hotspot user */
    public function addHotspotUser(array $params): bool {
        if (!$this->connected) return false;
        $cmd = ['/ip/hotspot/user/add'];
        foreach ($params as $k => $v) $cmd[] = '=' . $k . '=' . $v;
        $raw = $this->rawCommand($cmd);
        return $this->checkDone($raw);
    }

    /** Update an existing Hotspot user by .id (password, profile, comment) */
    public function updateHotspotUser(string $id, string $password, string $profile, string $comment): bool {
        if (!$this->connected) return false;
        $raw = $this->rawCommand([
            '/ip/hotspot/user/set',
            '=.id='      . $id,
            '=password=' . $password,
            '=profile='  . $profile,
            '=comment='  . $comment,
        ]);
        return $this->checkDone($raw);
    }

    /** Set Hotspot user disabled state */
    public function setHotspotUserDisabled(string $username, bool $disabled): bool {
        if (!$this->connected) return false;
        $rows = $this->query('/ip/hotspot/user/print', [], ['?name=' . $username]);
        if (empty($rows[0]['.id'])) return false;
        $raw = $this->rawCommand([
            '/ip/hotspot/user/set',
            '=.id=' . $rows[0]['.id'],
            '=disabled=' . ($disabled ? 'yes' : 'no'),
        ]);
        return $this->checkDone($raw);
    }

    /** Set comment on a Hotspot user by name */
    public function setHotspotUserComment(string $username, string $comment): bool {
        if (!$this->connected) return false;
        $rows = $this->query('/ip/hotspot/user/print', [], ['?name=' . $username]);
        if (empty($rows[0]['.id'])) return false;
        $raw = $this->rawCommand([
            '/ip/hotspot/user/set',
            '=.id=' . $rows[0]['.id'],
            '=comment=' . $comment,
        ]);
        return $this->checkDone($raw);
    }

    /** Remove Hotspot user by name */
    public function removeHotspotUser(string $username): bool {
        if (!$this->connected) return false;
        $rows = $this->query('/ip/hotspot/user/print', [], ['?name=' . $username]);
        if (empty($rows[0]['.id'])) return false;
        $raw = $this->rawCommand(['/ip/hotspot/user/remove', '=.id=' . $rows[0]['.id']]);
        return $this->checkDone($raw);
    }

    /** Change PPP secret profile */
    public function setPPPSecretProfile(string $name, string $profile, bool $enable = true): bool {
        if (!$this->connected) return false;
        $rows = $this->query('/ppp/secret/print', [], ['?name=' . $name]);
        if (empty($rows[0]['.id'])) return false;
        $cmd = [
            '/ppp/secret/set',
            '=.id=' . $rows[0]['.id'],
            '=profile=' . $profile,
        ];
        if ($enable) $cmd[] = '=disabled=no';
        $raw = $this->rawCommand($cmd);
        return $this->checkDone($raw);
    }

    /** Change Hotspot user profile */
    public function setHotspotUserProfile(string $username, string $profile, bool $enable = true): bool {
        if (!$this->connected) return false;
        $rows = $this->query('/ip/hotspot/user/print', [], ['?name=' . $username]);
        if (empty($rows[0]['.id'])) return false;
        $cmd = [
            '/ip/hotspot/user/set',
            '=.id=' . $rows[0]['.id'],
            '=profile=' . $profile,
        ];
        if ($enable) $cmd[] = '=disabled=no';
        $raw = $this->rawCommand($cmd);
        return $this->checkDone($raw);
    }

    // ── User Manager (RADIUS) ─────────────────────────────────

    /**
     * Cached UM path prefix: '/user-manager' (ROS v7) or '/tool/user-manager' (ROS v6).
     * '' means UM is not installed. Null means not yet detected.
     */
    private ?string $umPrefix = null;

    /** Pre-set the UM prefix from a DB-cached value to skip the detection round-trip. */
    public function setUmPrefix(string $prefix): void {
        $this->umPrefix = $prefix;
    }

    /** Return the detected (or pre-set) UM prefix; null = not yet detected. */
    public function getDetectedUmPrefix(): ?string {
        return $this->umPrefix;
    }

    /**
     * Detect the correct User Manager API prefix for this RouterOS version.
     * ROS v7 uses /user-manager/...  — ROS v6 uses /tool/user-manager/...
     * Result is cached for the lifetime of the connection.
     */
    public function umPrefix(): string {
        if ($this->umPrefix !== null) return $this->umPrefix;

        // Try v7 path first — !fatal closes the TCP connection (router has no UM installed)
        $raw = $this->rawCommand(['/user-manager/print']);
        foreach ($raw as $word) {
            if ($word === '!fatal') {
                // Connection was closed by router — UM not installed
                $this->umPrefix = '';
                return $this->umPrefix;
            }
            if ($word === '!trap') {
                // UM exists but at v6 path
                $this->umPrefix = '/tool/user-manager';
                return $this->umPrefix;
            }
        }
        $this->umPrefix = '/user-manager';
        return $this->umPrefix;
    }

    /**
     * Check whether User Manager is installed and reachable on this router.
     */
    public function isUserManagerInstalled(): bool {
        if (!$this->connected) return false;
        $prefix = $this->umPrefix();
        $raw    = $this->rawCommand([$prefix . '/print']);
        foreach ($raw as $word) {
            if ($word === '!trap' || $word === '!fatal') return false;
        }
        return true;
    }

    /** Get User Manager configuration (enabled, certificate, etc.) */
    public function getUserManagerSettings(): array {
        if (!$this->connected) return [];
        $rows = $this->query($this->umPrefix() . '/print');
        return $rows[0] ?? [];
    }

    /** Get all configured RADIUS clients on the router (/radius/print) */
    public function getRadiusClients(): array {
        if (!$this->connected) return [];
        return $this->query('/radius/print');
    }

    /** Get all User Manager groups (/user-manager/user/group/print) */
    public function getUserManagerGroups(): array {
        if (!$this->connected) return [];
        return $this->query($this->umPrefix() . '/user/group/print');
    }

    /** Get all User Manager users (optionally filtered by group) */
    public function getUserManagerUsers(string $group = ''): array {
        if (!$this->connected) return [];
        $base = $this->umPrefix() . '/user/print';
        return $group !== ''
            ? $this->query($base, [], ['?group=' . $group])
            : $this->query($base);
    }

    /** Get active User Manager sessions */
    public function getUserManagerActiveSessions(): array {
        if (!$this->connected) return [];
        $prefix = $this->umPrefix();
        if ($prefix === '') return []; // UM not installed on this router
        $base = $prefix . '/session/print';

        // Try router-side filter first (skips historical records)
        $rows = $this->query($base, [], ['?terminated=never']);
        if (!empty($rows)) return $rows;

        // Fallback: fetch all and filter in PHP
        $all = $this->query($base);
        return array_values(array_filter(
            $all,
            fn($r) => !isset($r['terminated']) || $r['terminated'] === 'never'
        ));
    }

    private static function isUserManagerSessionActive(array $row): bool {
        if (isset($row['active'])) {
            return in_array(strtolower((string)$row['active']), ['yes', 'true', '1'], true);
        }
        if (isset($row['status'])) {
            return in_array(strtolower((string)$row['status']), ['active', 'accounting', 'start', 'started'], true);
        }
        if (isset($row['terminated'])) {
            return in_array(strtolower((string)$row['terminated']), ['never', '', 'no', 'false'], true);
        }
        return true;
    }

    /** Best-effort removal of active User Manager sessions for a user. */
    public function disconnectUserManagerSessions(string $username): int {
        if (!$this->connected) return 0;
        $prefix = $this->umPrefix();
        if ($prefix === '') return 0;

        $sessions = $this->getUserManagerActiveSessions();
        $removed = 0;
        foreach ($sessions as $session) {
            $sessionUser = (string)($session['user'] ?? $session['user-name'] ?? $session['username'] ?? $session['name'] ?? '');
            if ($sessionUser !== $username || empty($session['.id'])) continue;

            $raw = $this->rawCommand([$prefix . '/session/remove', '=.id=' . $session['.id']]);
            if ($this->checkDone($raw)) {
                $removed++;
            }
        }
        return $removed;
    }

    /** Best-effort User Manager disconnect with broader filters for manual operator actions. */
    public function disconnectUserManagerSessionsRobust(string $username): int {
        $prefix = $this->umPrefix();
        if ($prefix === '') return 0;
        $sessions = $this->findUserManagerActiveSessionsByUserRobust($username);
        $removed = 0;
        foreach ($sessions as $session) {
            if (empty($session['.id'])) continue;

            $raw = $this->rawCommand([$prefix . '/session/remove', '=.id=' . $session['.id']]);
            if ($this->checkDone($raw)) {
                $removed++;
            }
        }
        return $removed;
    }

    /** Find active User Manager sessions with broader filters for manual operator actions. */
    public function findUserManagerActiveSessionsByUserRobust(string $username): array {
        if (!$this->connected) return [];
        $username = trim($username);
        if ($username === '') return [];

        $prefix = $this->umPrefix();
        if ($prefix === '') return [];

        $base = $prefix . '/session/print';
        $sessions = $this->query($base, [], ['?user=' . $username]);
        $matches = array_values(array_filter(
            $sessions,
            fn($session) => self::isUserManagerSessionActive($session)
                && self::userMatches($session, ['user', 'user-name', 'username', 'name', 'login'], $username)
        ));
        if (!empty($matches)) return $matches;

        $sessions = $this->query($base, [], ['?name=' . $username]);
        $matches = array_values(array_filter(
            $sessions,
            fn($session) => self::isUserManagerSessionActive($session)
                && self::userMatches($session, ['user', 'user-name', 'username', 'name', 'login'], $username)
        ));
        if (!empty($matches)) return $matches;

        $sessions = $this->query($base, [], ['?active=yes']);
        $matches = array_values(array_filter(
            $sessions,
            fn($session) => self::isUserManagerSessionActive($session)
                && self::userMatches($session, ['user', 'user-name', 'username', 'name', 'login'], $username)
        ));
        if (!empty($matches)) return $matches;

        $sessions = $this->getUserManagerActiveSessions();
        return array_values(array_filter(
            $sessions,
            fn($session) => self::isUserManagerSessionActive($session)
                && self::userMatches($session, ['user', 'user-name', 'username', 'name', 'login'], $username)
        ));
    }

    /** Get a single User Manager user by name; returns [] if not found */
    public function getUserManagerUser(string $name): array {
        if (!$this->connected) return [];
        $rows = $this->query($this->umPrefix() . '/user/print', [], ['?name=' . $name]);
        return $rows[0] ?? [];
    }

    /** Find a User Manager user with broader username fields for manual operator actions. */
    public function findUserManagerUserByNameRobust(string $name): array {
        if (!$this->connected) return [];
        $name = trim($name);
        if ($name === '') return [];

        $prefix = $this->umPrefix();
        if ($prefix === '') {
            $this->error = 'User Manager is not available on this router';
            return [];
        }

        $base = $prefix . '/user/print';
        foreach (['name', 'user', 'username', 'login'] as $field) {
            $rows = $this->query($base, [], ['?' . $field . '=' . $name]);
            $matches = array_values(array_filter(
                $rows,
                fn($row) => self::userMatches($row, ['name', 'user', 'username', 'login'], $name)
            ));
            if (!empty($matches)) return $matches[0];
        }

        $rows = $this->query($base);
        $matches = array_values(array_filter(
            $rows,
            fn($row) => self::userMatches($row, ['name', 'user', 'username', 'login'], $name)
        ));
        return $matches[0] ?? [];
    }

    /** Add User Manager user (name, password, group optional) */
    public function addUserManagerUser(array $params): bool {
        if (!$this->connected) return false;
        $cmd = [$this->umPrefix() . '/user/add'];
        foreach ($params as $k => $v) $cmd[] = '=' . $k . '=' . $v;
        $raw = $this->rawCommand($cmd);
        return $this->checkDone($raw);
    }

    /** Disable or enable a User Manager user using robust lookup for manual operator actions. */
    public function setUserManagerUserDisabledRobust(string $name, bool $disabled): bool {
        if (!$this->connected) return false;
        $prefix = $this->umPrefix();
        if ($prefix === '') {
            $this->error = 'User Manager is not available on this router';
            return false;
        }

        $user = $this->findUserManagerUserByNameRobust($name);
        if (empty($user['.id'])) {
            $this->error = "User Manager user '{$name}' was not found";
            return false;
        }

        $raw = $this->rawCommand([
            $prefix . '/user/set',
            '=.id=' . $user['.id'],
            '=disabled=' . ($disabled ? 'yes' : 'no'),
        ]);
        return $this->checkDone($raw);
    }

    /** Set comment on a User Manager user using robust lookup */
    public function setUserManagerUserComment(string $name, string $comment): bool {
        if (!$this->connected) return false;
        $prefix = $this->umPrefix();
        if ($prefix === '') return false;

        $user = $this->findUserManagerUserByNameRobust($name);
        if (empty($user['.id'])) return false;

        $raw = $this->rawCommand([
            $prefix . '/user/set',
            '=.id='     . $user['.id'],
            '=comment=' . $comment,
        ]);
        return $this->checkDone($raw);
    }

    /**
     * Change a User Manager user's group/profile for manual operator actions.
     * Some RouterOS builds expose a user group, while others expose profile names.
     */
    public function setUserManagerUserGroupRobust(string $name, string $group, bool $enable = true): bool {
        if (!$this->connected) return false;
        $group = trim($group);
        if ($group === '') {
            $this->error = 'No User Manager group/profile was provided';
            return false;
        }

        $prefix = $this->umPrefix();
        if ($prefix === '') {
            $this->error = 'User Manager is not available on this router';
            return false;
        }

        $user = $this->findUserManagerUserByNameRobust($name);
        if (empty($user['.id'])) {
            $this->error = "User Manager user '{$name}' was not found";
            return false;
        }

        $baseCmd = [
            $prefix . '/user/set',
            '=.id=' . $user['.id'],
        ];
        if ($enable) $baseCmd[] = '=disabled=no';

        $this->error = '';
        $raw = $this->rawCommand(array_merge($baseCmd, ['=group=' . $group]));
        if ($this->checkDone($raw)) return true;
        $groupError = $this->error;

        $this->error = '';
        $raw = $this->rawCommand(array_merge($baseCmd, ['=profile=' . $group]));
        if ($this->checkDone($raw)) return true;
        $profileError = $this->error;

        $this->error = $profileError ?: $groupError ?: "Could not assign User Manager group/profile '{$group}'";
        return false;
    }

    /** Disable or enable a User Manager user */
    public function setUserManagerUserDisabled(string $name, bool $disabled): bool {
        if (!$this->connected) return false;
        $rows = $this->query($this->umPrefix() . '/user/print', [], ['?name=' . $name]);
        if (empty($rows[0]['.id'])) return false;
        $raw = $this->rawCommand([
            $this->umPrefix() . '/user/set',
            '=.id=' . $rows[0]['.id'],
            '=disabled=' . ($disabled ? 'yes' : 'no'),
        ]);
        return $this->checkDone($raw);
    }

    /** Change User Manager user group */
    public function setUserManagerUserGroup(string $name, string $group, bool $enable = true): bool {
        if (!$this->connected) return false;
        $rows = $this->query($this->umPrefix() . '/user/print', [], ['?name=' . $name]);
        if (empty($rows[0]['.id'])) return false;
        $cmd = [
            $this->umPrefix() . '/user/set',
            '=.id=' . $rows[0]['.id'],
            '=group=' . $group,
        ];
        if ($enable) $cmd[] = '=disabled=no';
        $raw = $this->rawCommand($cmd);
        return $this->checkDone($raw);
    }

    /** Update password, group and re-enable an existing User Manager user */
    public function updateUserManagerUser(string $name, string $password, string $group): bool {
        if (!$this->connected) return false;
        $rows = $this->query($this->umPrefix() . '/user/print', [], ['?name=' . $name]);
        if (empty($rows[0]['.id'])) return false;
        $raw = $this->rawCommand([
            $this->umPrefix() . '/user/set',
            '=.id='      . $rows[0]['.id'],
            '=password=' . $password,
            '=group='    . $group,
            '=disabled=no',
        ]);
        return $this->checkDone($raw);
    }

    /** Remove User Manager user by name */
    public function removeUserManagerUser(string $name): bool {
        if (!$this->connected) return false;
        $rows = $this->query($this->umPrefix() . '/user/print', [], ['?name=' . $name]);
        if (empty($rows[0]['.id'])) return false;
        $raw = $this->rawCommand([$this->umPrefix() . '/user/remove', '=.id=' . $rows[0]['.id']]);
        return $this->checkDone($raw);
    }

    // ── Low-level protocol ────────────────────────────────────
    private function rawCommand(array $words): array {
        $this->sendSentence($words);
        return $this->receiveSentences();
    }

    private function sendSentence(array $words): void {
        foreach ($words as $word) {
            $this->sendWord((string)$word);
        }
        $this->sendWord(''); // end of sentence
    }

    private function sendWord(string $word): void {
        $len    = strlen($word);
        $lenStr = $this->encodeLength($len);
        $this->socketWrite($lenStr . $word);
    }

    private function encodeLength(int $len): string {
        if ($len < 0x80) {
            return chr($len);
        }
        if ($len < 0x4000) {
            $len |= 0x8000;
            return pack('n', $len);
        }
        if ($len < 0x200000) {
            $len |= 0xC00000;
            return chr(($len >> 16) & 0xFF) . pack('n', $len & 0xFFFF);
        }
        if ($len < 0x10000000) {
            $len |= 0xE0000000;
            return pack('N', $len);
        }
        return chr(0xF0) . pack('N', $len);
    }

    private function socketWrite(string $data): void {
        $total   = strlen($data);
        $written = 0;
        while ($written < $total) {
            $wrote = @fwrite($this->socket, substr($data, $written));
            if ($wrote === false || $wrote === 0) {
                // Socket is dead — clean up so callers don't retry
                if ($this->socket) { @fclose($this->socket); $this->socket = null; }
                $this->connected = false;
                throw new RuntimeException('Socket write failed — connection lost');
            }
            $written += $wrote;
        }
    }

    private function receiveSentences(): array {
        $response  = [];
        $deadline  = microtime(true) + $this->timeout;
        $emptyRuns = 0;

        while (true) {
            if (microtime(true) > $deadline) {
                $this->error = 'Response timeout';
                break;
            }
            $word = $this->readWord();
            if ($word === '') {
                // A timed-out or EOF readBytes returns '' — stop to avoid infinite loop
                if ($this->socket && feof($this->socket)) {
                    // Remote closed the connection — mark dead so next write fails cleanly
                    @fclose($this->socket);
                    $this->socket    = null;
                    $this->connected = false;
                    break;
                }
                if ($this->error !== '') break;
                if (++$emptyRuns > 3) break;
                continue;
            }
            $emptyRuns  = 0;
            $response[] = $word;
            if ($word === '!done') {
                while (($w = $this->readWord()) !== '') {
                    $response[] = $w;
                }
                break;
            }
            if ($word === '!fatal') {
                while (($w = $this->readWord()) !== '') {
                    $response[] = $w;
                }
                // !fatal means RouterOS has closed the connection
                if ($this->socket) { @fclose($this->socket); $this->socket = null; }
                $this->connected = false;
                break;
            }
            if ($word === '!trap') {
                while (($w = $this->readWord()) !== '') {
                    $response[] = $w;
                }
                // !trap is always followed by !done — keep reading
            }
        }
        return $response;
    }

    private function readWord(): string {
        $firstByte = $this->readBytes(1);
        if ($firstByte === '') return '';

        $b = ord($firstByte);
        if ($b < 0x80) {
            $len = $b;
        } elseif ($b < 0xC0) {
            $next = $this->readBytes(1);
            if ($next === '') return '';
            $len = (($b & 0x3F) << 8) | ord($next);
        } elseif ($b < 0xE0) {
            $next = $this->readBytes(2);
            if (strlen($next) < 2) return '';
            $len = (($b & 0x1F) << 16) | (ord($next[0]) << 8) | ord($next[1]);
        } elseif ($b < 0xF0) {
            $next = $this->readBytes(3);
            if (strlen($next) < 3) return '';
            $len = (($b & 0x0F) << 24) | (ord($next[0]) << 16) | (ord($next[1]) << 8) | ord($next[2]);
        } else {
            $next = $this->readBytes(4);
            if (strlen($next) < 4) return '';
            $len = (ord($next[0]) << 24) | (ord($next[1]) << 16) | (ord($next[2]) << 8) | ord($next[3]);
        }

        if ($len === 0) return '';
        return $this->readBytes($len);
    }

    private function readBytes(int $length): string {
        if ($length <= 0) return '';
        $data     = '';
        $deadline = microtime(true) + $this->timeout;

        while (strlen($data) < $length) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $this->error = 'Read timeout';
                break;
            }
            // stream_select enforces a real wall-clock timeout on blocking sockets
            $read  = [$this->socket];
            $write = $except = null;
            $sec   = (int)$remaining;
            $usec  = (int)(($remaining - $sec) * 1_000_000);
            $ready = @stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false || $ready === 0) {
                $this->error = 'Read timeout';
                break;
            }
            $chunk = fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                if (feof($this->socket)) break;
                continue;
            }
            $data .= $chunk;
        }
        return $data;
    }

    // ── Response parsing ──────────────────────────────────────
    public static function parseResponse(array $raw): array {
        $result  = [];
        $current = [];
        foreach ($raw as $word) {
            if ($word === '!re') {
                if (!empty($current)) {
                    $result[] = $current;
                }
                $current = [];
            } elseif ($word === '!done') {
                if (!empty($current)) {
                    $result[] = $current;
                }
                break;
            } elseif ($word === '!trap' || $word === '!fatal') {
                break;
            } elseif (str_starts_with($word, '=')) {
                $pos = strpos($word, '=', 1);
                if ($pos !== false) {
                    $key           = substr($word, 1, $pos - 1);
                    $val           = substr($word, $pos + 1);
                    $current[$key] = $val;
                }
            }
        }
        // Push last item in case !done was never received
        if (!empty($current)) {
            $result[] = $current;
        }
        return $result;
    }

    private function checkDone(array $raw): bool {
        foreach ($raw as $word) {
            if ($word === '!done') return true;
            if ($word === '!trap' || $word === '!fatal') {
                $this->error = $this->extractAttribute($raw, 'message') ?? 'Command failed';
                return false;
            }
        }
        return false;
    }

    private function extractAttribute(array $raw, string $key): ?string {
        foreach ($raw as $word) {
            if (str_starts_with($word, '=' . $key . '=')) {
                return substr($word, strlen($key) + 2);
            }
        }
        return null;
    }

    // ── PPP / Hotspot Profile management ─────────────────────

    /** Get all PPP profiles */
    public function getPPPProfiles(): array {
        return $this->query('/ppp/profile/print');
    }

    /** Get all Hotspot user profiles */
    public function getHotspotUserProfiles(): array {
        return $this->query('/ip/hotspot/user/profile/print');
    }

    /** Add PPP profile to router */
    public function addPPPProfile(string $name, string $rateLimit): bool {
        if (!$this->connected) return false;
        $raw = $this->rawCommand(['/ppp/profile/add', '=name=' . $name, '=rate-limit=' . $rateLimit]);
        return $this->checkDone($raw);
    }

    /** Update existing PPP profile or add if not found */
    public function updatePPPProfile(string $name, string $rateLimit): bool {
        if (!$this->connected) return false;
        $rows = $this->query('/ppp/profile/print', [], ['?name=' . $name]);
        if (!empty($rows[0]['.id'])) {
            $raw = $this->rawCommand(['/ppp/profile/set', '=.id=' . $rows[0]['.id'], '=rate-limit=' . $rateLimit]);
            return $this->checkDone($raw);
        }
        return $this->addPPPProfile($name, $rateLimit);
    }

    /** Add hotspot user profile */
    public function addHotspotUserProfile(string $name, string $rateLimit): bool {
        if (!$this->connected) return false;
        $raw = $this->rawCommand(['/ip/hotspot/user/profile/add', '=name=' . $name, '=rate-limit=' . $rateLimit]);
        return $this->checkDone($raw);
    }

    /** Update existing hotspot user profile or add if not found */
    public function updateHotspotUserProfile(string $name, string $rateLimit): bool {
        if (!$this->connected) return false;
        $rows = $this->query('/ip/hotspot/user/profile/print', [], ['?name=' . $name]);
        if (!empty($rows[0]['.id'])) {
            $raw = $this->rawCommand(['/ip/hotspot/user/profile/set', '=.id=' . $rows[0]['.id'], '=rate-limit=' . $rateLimit]);
            return $this->checkDone($raw);
        }
        return $this->addHotspotUserProfile($name, $rateLimit);
    }

    /**
     * Sync a plan as a profile on the router.
     * Returns ['ppp' => bool, 'hotspot' => bool] based on plan_type.
     */
    public function syncPlanProfile(string $name, int $speedMbps, ?int $burstMbps, string $planType): array {
        $dl = $speedMbps . 'M';
        $ul = $speedMbps . 'M';
        if ($burstMbps && $burstMbps > $speedMbps) {
            $bl = $burstMbps . 'M';
            $rateLimit = "{$dl}/{$ul} {$bl}/{$bl} {$dl}/{$ul}";
        } else {
            $rateLimit = "{$dl}/{$ul}";
        }

        $results = [];
        if ($planType === 'ppp' || $planType === 'both') {
            $results['ppp'] = $this->updatePPPProfile($name, $rateLimit);
        }
        if ($planType === 'hotspot' || $planType === 'both') {
            $results['hotspot'] = $this->updateHotspotUserProfile($name, $rateLimit);
        }
        return $results;
    }

    /** Get interface list */
    public function getInterfaces(): array {
        return $this->query('/interface/print');
    }

    /** Get IP address pools */
    public function getIPPools(): array {
        return $this->query('/ip/pool/print');
    }

    /** Get simple queues */
    public function getSimpleQueues(): array {
        return $this->query('/queue/simple/print');
    }

    /** Get queue tree entries */
    public function getQueueTree(): array {
        return $this->query('/queue/tree/print');
    }

    // ── Static factory with auto-disconnect ──────────────────
    public static function testConnection(string $host, int $port, string $user, string $pass, int $timeout = 5): array {
        $api = new self($host, $port, $timeout);
        if ($api->connect($user, $pass)) {
            $identity = $api->getIdentity();
            $resource = $api->getSystemResource();
            $api->disconnect();
            return ['success' => true, 'identity' => $identity, 'resource' => $resource];
        }
        return ['success' => false, 'error' => $api->error];
    }
}

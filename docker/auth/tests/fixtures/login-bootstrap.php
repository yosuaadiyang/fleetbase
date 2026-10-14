<?php

use Fleetbase\Http\Controllers\Internal\v1\AuthController;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Http\Requests\ChangePasswordRequest;
use Fleetbase\Http\Requests\JoinOrganizationRequest;
use Fleetbase\Http\Requests\LoginRequest;
use Fleetbase\Models\User;
use Fleetbase\Support\TwoFactorAuth;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str as SupportStr;

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return getcwd() . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}

if (!function_exists('Fleetbase\\Http\\Controllers\\Internal\\v1\\event')) {
    eval('namespace Fleetbase\\Http\\Controllers\\Internal\\v1; function event($event = null) { return $event; }');
}

class AuthControllerLoginBootstrapCacheFake
{
    public array $rememberCalls = [];

    public array $values = [];

    public function tags(array|string $tags): self
    {
        return $this;
    }

    public function remember(string $key, mixed $ttl, Closure $callback): mixed
    {
        $this->rememberCalls[] = [$key, $ttl];

        if (!array_key_exists($key, $this->values)) {
            $this->values[$key] = $callback();
        }

        return $this->values[$key];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function increment(string $key, int $value = 1): int
    {
        $this->values[$key] = (int) ($this->values[$key] ?? 0) + $value;

        return $this->values[$key];
    }

    public function forget(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }
}

class AuthControllerLoginBootstrapHashFake
{
    public function make(mixed $value, array $options = []): string
    {
        return password_hash((string) $value, PASSWORD_BCRYPT);
    }

    public function check(mixed $value, string $hashedValue, array $options = []): bool
    {
        return password_verify((string) $value, $hashedValue);
    }
}

class AuthControllerLoginBootstrapResponseCacheFake
{
    public function clear(array $tags = []): bool
    {
        return true;
    }
}

class AuthControllerLoginBootstrapMailFake
{
    public array $sent       = [];
    private mixed $recipient = null;

    public function to(mixed $recipient): self
    {
        $this->recipient = $recipient;

        return $this;
    }

    public function send(mixed $mailable): void
    {
        $this->sent[] = [$this->recipient, $mailable::class];
    }
}

class AuthControllerLoginBootstrapRedisFake
{
    public array $values = [];

    public array $sets = [];

    public function set(string $key, mixed $value, mixed ...$options): bool
    {
        $this->values[$key] = $value;
        $this->sets[]       = compact('key', 'value', 'options');

        return true;
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function del(?string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function connection(): self
    {
        return $this;
    }
}

/**
 * Minimal stand-in for the resolved 'auth' guard so controller calls to the
 * Auth facade (Auth::validate / Auth::login) work without a real auth manager.
 */
class AuthControllerLoginBootstrapAuthGuardFake
{
    public array $loggedIn      = [];
    public bool $validateResult = true;

    public function validate(array $credentials = []): bool
    {
        return $this->validateResult;
    }

    public function login($user, bool $remember = false): void
    {
        $this->loggedIn[] = $user;
    }

    public function user()
    {
        return $this->loggedIn[count($this->loggedIn) - 1] ?? null;
    }
}

class AuthControllerLoginBootstrapPermissionRegistrarFake
{
    public string $pivotRole       = 'role_id';
    public string $pivotPermission = 'permission_id';
    public bool $teams             = false;
    public string $teamsKey        = 'team_id';

    public function getRoleClass(): string
    {
        return Fleetbase\Models\Role::class;
    }

    public function getPermissionClass(): string
    {
        return Fleetbase\Models\Permission::class;
    }
}

class AuthControllerLoginBootstrapUserSpy extends User
{
    public function hasRole($roles, ?string $guard = null): bool
    {
        return false;
    }

    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        return false;
    }
}

function auth_controller_login_bootstrap_database(): Capsule
{
    EloquentModel::unsetConnectionResolver();
    EloquentModel::clearBootedModels();

    if (!SupportStr::hasMacro('humanize')) {
        SupportStr::macro('humanize', (new Fleetbase\Expansions\Str())->humanize());
    }

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'app.env'                                      => 'testing',
        'app.timezone'                                 => 'UTC',
        'app.key'                                      => 'base64:' . base64_encode(str_repeat('a', 32)),
        'auth.defaults.guard'                          => 'sanctum',
        'auth.guards.sanctum.provider'                 => 'users',
        'auth.providers.users.model'                   => User::class,
        'database.default'                             => 'mysql',
        'database.connections.mysql'                   => $connection,
        'fleetbase.connection.db'                      => 'mysql',
        'activitylog.enabled'                          => false,
        'permission.models.permission'                 => Fleetbase\Models\Permission::class,
        'permission.models.role'                       => Fleetbase\Models\Role::class,
        'permission.table_names.permissions'           => 'permissions',
        'permission.table_names.roles'                 => 'roles',
        'permission.table_names.model_has_permissions' => 'model_has_permissions',
        'permission.table_names.model_has_roles'       => 'model_has_roles',
        'permission.column_names.model_morph_key'      => 'model_uuid',
    ]);
    $container->instance(Illuminate\Contracts\Config\Repository::class, $container->make('config'));

    $cache = new AuthControllerLoginBootstrapCacheFake();
    $container->instance('cache', $cache);
    $container->instance('hash', new AuthControllerLoginBootstrapHashFake());
    $container->instance('redis', new AuthControllerLoginBootstrapRedisFake());
    $container->instance('responsecache', new AuthControllerLoginBootstrapResponseCacheFake());
    $container->instance(Spatie\Permission\PermissionRegistrar::class, new AuthControllerLoginBootstrapPermissionRegistrarFake());
    Cache::swap($cache);
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('hash');
    Facade::clearResolvedInstance('redis');
    Facade::clearResolvedInstance('responsecache');

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $schema = app('db')->connection('mysql')->getSchemaBuilder();
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable()->index();
        $table->string('phone')->nullable()->index();
        $table->string('password')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->timestamp('email_verified_at')->nullable();
        $table->timestamp('phone_verified_at')->nullable();
        $table->timestamp('last_login')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->unique();
        $table->text('value')->nullable();
    });
    $schema->create('personal_access_tokens', function ($table) {
        $table->increments('id');
        $table->morphs('tokenable');
        $table->string('name');
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });
    $schema->create('companies', function ($table) {
        $table->increments('id');
        $table->string('uuid')->unique();
        $table->string('public_id')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('description')->nullable();
        $table->string('phone')->nullable();
        $table->string('logo_uuid')->nullable();
        $table->string('backdrop_uuid')->nullable();
        $table->text('options')->nullable();
        $table->string('currency')->nullable();
        $table->string('country')->nullable();
        $table->string('timezone')->nullable();
        $table->string('plan')->nullable();
        $table->timestamp('trial_ends_at')->nullable();
        $table->string('status')->nullable();
        $table->string('type')->nullable();
        $table->string('slug')->nullable();
        $table->timestamp('onboarding_completed_at')->nullable();
        $table->string('onboarding_completed_by_uuid')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('company_users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('user_uuid');
        $table->string('company_uuid');
        $table->string('status')->nullable();
        $table->boolean('external')->default(false);
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('roles', function ($table) {
        $table->string('id')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('name');
        $table->string('guard_name')->default('sanctum');
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('permissions', function ($table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('guard_name')->default('sanctum');
        $table->timestamps();
    });
    $schema->create('model_has_roles', function ($table) {
        $table->string('role_id');
        $table->string('model_type');
        $table->string('model_uuid');
    });
    $schema->create('model_has_permissions', function ($table) {
        $table->string('permission_id');
        $table->string('model_type');
        $table->string('model_uuid');
    });
    $schema->create('role_has_permissions', function ($table) {
        $table->string('permission_id');
        $table->string('role_id');
    });
    $schema->create('policies', function ($table) {
        $table->string('id')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('name');
        $table->string('guard_name')->default('sanctum');
        $table->string('service')->nullable();
        $table->text('description')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('model_has_policies', function ($table) {
        $table->string('policy_id');
        $table->string('model_type');
        $table->string('model_uuid');
    });
    $schema->create('invites', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->string('protocol')->nullable();
        $table->string('reason')->nullable();
        $table->json('recipients')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });

    session()->flush();

    return $capsule;
}

function auth_controller_login_insert_user(Capsule $capsule, array $attributes = []): void
{
    $capsule->getConnection('mysql')->table('users')->insert(array_merge([
        'uuid'              => '11111111-1111-4111-8111-111111111111',
        'company_uuid'      => 'company-1',
        'name'              => 'Auth User',
        'email'             => 'auth@example.test',
        'phone'             => '+15555550123',
        'password'          => password_hash('correct-password', PASSWORD_BCRYPT),
        'type'              => 'admin',
        'status'            => 'active',
        'email_verified_at' => '2026-07-18 10:00:00',
        'phone_verified_at' => null,
        'last_login'        => null,
        'deleted_at'        => null,
        'created_at'        => '2026-07-18 10:00:00',
        'updated_at'        => '2026-07-18 10:00:00',
    ], $attributes));
}

function auth_controller_login_request(array $input): LoginRequest
{
    return LoginRequest::create('/int/v1/auth/login', 'POST', $input);
}

function auth_controller_bootstrap_request(User $user, string $token = 'bootstrap-token'): Request
{
    $request = Request::create('/int/v1/auth/bootstrap', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
    ]);
    $request->setUserResolver(fn () => $user);

    return $request;
}

function auth_controller_authenticated_request(string $method, array $input, User $user, string $uri = '/int/v1/auth', ?string $requestClass = null): Request
{
    $requestClass ??= Request::class;
    $request = $requestClass::create($uri, $method, $input);
    $request->setUserResolver(fn () => $user);
    app()->instance('request', $request);
    session(['user' => $user->uuid, 'company' => $user->company_uuid]);

    return $request;
}

function auth_controller_join_organization_request(User $user, string $publicId): JoinOrganizationRequest
{
    /** @var JoinOrganizationRequest $request */
    $request = auth_controller_authenticated_request(
        'POST',
        ['next' => $publicId],
        $user,
        '/int/v1/organizations/join',
        JoinOrganizationRequest::class
    );

    return $request;
}

function auth_controller_insert_company(Capsule $capsule, array $attributes = []): void
{
    $capsule->getConnection('mysql')->table('companies')->insert(array_merge([
        'uuid'                    => 'company-join',
        'public_id'               => 'company_join_public',
        'owner_uuid'              => 'owner-user',
        'name'                    => 'Joinable Company',
        'description'             => 'Accepts invited users',
        'phone'                   => '+15555550999',
        'logo_uuid'               => null,
        'backdrop_uuid'           => null,
        'options'                 => null,
        'currency'                => 'USD',
        'country'                 => 'US',
        'timezone'                => 'UTC',
        'plan'                    => 'starter',
        'trial_ends_at'           => null,
        'status'                  => 'active',
        'type'                    => 'business',
        'slug'                    => 'joinable-company',
        'onboarding_completed_at' => null,
        'created_at'              => '2026-07-18 10:00:00',
        'updated_at'              => '2026-07-18 10:00:00',
        'deleted_at'              => null,
    ], $attributes));
}

function auth_controller_insert_administrator_role(Capsule $capsule, ?string $companyUuid = null): void
{
    $capsule->getConnection('mysql')->table('roles')->insert([
        'id'           => 'role-' . ($companyUuid ?: 'global') . '-administrator',
        'company_uuid' => $companyUuid,
        'name'         => 'Administrator',
        'guard_name'   => 'sanctum',
        'created_at'   => '2026-07-18 10:00:00',
        'updated_at'   => '2026-07-18 10:00:00',
        'deleted_at'   => null,
    ]);
}

function auth_controller_insert_join_invite(Capsule $capsule, string $companyUuid, string $email): void
{
    $capsule->getConnection('mysql')->table('invites')->insert([
        'uuid'            => 'invite-' . $companyUuid,
        'company_uuid'    => $companyUuid,
        'subject_uuid'    => $companyUuid,
        'subject_type'    => Fleetbase\Models\Company::class,
        'created_by_uuid' => 'owner-user',
        'protocol'        => 'email',
        'reason'          => 'join_company',
        'recipients'      => json_encode([$email]),
        'expires_at'      => Carbon::now()->addDay()->toDateTimeString(),
        'deleted_at'      => null,
        'created_at'      => '2026-07-18 10:00:00',
        'updated_at'      => '2026-07-18 10:00:00',
    ]);
}


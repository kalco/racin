# Racin

[![tests](https://github.com/kalco/racin/actions/workflows/tests.yml/badge.svg)](https://github.com/kalco/racin/actions/workflows/tests.yml)

A small, fast PHP framework for JSON APIs, by Roka: Rails-style controllers and generators,
ActiveRecord-style models with relations, serializers, JWT auth with roles/permissions, queues, mail,
image uploads, translations and a WebSocket server.

- PHP **8.3+** (tested on 8.3, 8.4 and 8.5), strict types throughout
- Only dependencies: `psr/container`, `psr/log` (optional extensions: `gd`, `redis`, `pdo_pgsql`, `intl`)
- API only: every response is JSON, every error is `{"message": ..., "errors": ...}`

## Installation

```bash
composer require roka/racin
```

The smallest application is a front controller and a routes file:

```php
// public/index.php  (the web server's document root is public/)
require dirname(__DIR__) . '/vendor/autoload.php';

(new Racin\Application(dirname(__DIR__)))->run();
```

```php
// routes/api.php  ($router and $app are in scope)
$router->get('/ping', fn () => ['pong' => true]);
```

Everything else is optional and has defaults: `.env` (`APP_KEY`, `DB_*`, ...), `config/*.php`
(`app`, `auth`, `database`, `cache`, `cors`, `filesystems`, `logging`, `mail`, `queue`, `broadcasting`),
`database/migrations`, `database/seeders`, `lang/`, `storage/` (logs, cache; created when needed).
Send every request that is not a file to `public/index.php` (Apache: a `RewriteRule ^ index.php [L]`
`.htaccess`, also passing the `Authorization` header with `E=HTTP_AUTHORIZATION:%{HTTP:Authorization}`).

The console is `vendor/bin/racin <command>` (run it in the project root); a project may also keep its
own `racin` file. The examples below write `php racin`.

```bash
vendor/bin/racin key:generate        # needs a .env file
vendor/bin/racin serve --port=8000   # development server
```

Generated code (`g scaffold`, `make:*`) goes to `app/` in the `App\` namespace and seeders to
`database/seeders` (`Database\Seeders\`), so add those to your project's `composer.json`:

```json
"autoload": {
    "psr-4": {
        "App\\": "app/",
        "Database\\Seeders\\": "database/seeders/"
    }
}
```

## Development

```bash
git clone https://github.com/kalco/racin.git && cd racin
composer install
composer test
```

Redis tests use `REDIS_HOST` (default `Redis`) and are skipped when it is unreachable; the WebSocket
test starts a real server process.

## Request lifecycle

```
public/index.php → Application::run()
  → bootstrap: .env → config/*.php → error handlers → core services → providers
  → Http\Kernel: global middleware (config app.middleware)
      → Router: match route → route middleware → controller action
  → return value → JsonResponse (array / JsonSerializable / model / paginator), null → 204
  → any exception → Exceptions\Handler → JSON error (rendered inside global middleware, so CORS still applies)
```

## Routing

Routes live in `routes/*.php` (`$router` and `$app` are in scope).

```php
use App\Controllers\PostController;
use Racin\Routing\Router;

$router->get('/posts', [PostController::class, 'index'])->name('posts.index');
$router->get('/posts/{id:\d+}', [PostController::class, 'show']);   // regex constraint
$router->get('/tags/{slug?}', [TagController::class, 'show']);       // optional
$router->post('/posts', 'App\Controllers\PostController@store');     // "Class@method"
$router->match(['PUT', 'PATCH'], '/posts/{id}', [PostController::class, 'update']);
$router->get('/ping', fn () => ['pong' => true]);                    // closures (not cacheable)

$router->group(['prefix' => 'v1', 'middleware' => ['auth'], 'as' => 'v1.'], function (Router $router) {
    $router->delete('/posts/{id}', [PostController::class, 'destroy'])->middleware('throttle:10,1');
});

$router->path('v1.posts.show', ['id' => 5]); // "/v1/posts/5"
```

Resource routes, like Rails' `resources :posts` (the action names are Rails'):

```php
$router->resource('posts', PostsController::class);
//   GET /posts index · POST /posts create · GET /posts/{id} show · PATCH|PUT /posts/{id} update · DELETE /posts/{id} destroy
$router->resource('posts.comments', CommentsController::class, only: ['index', 'create']);
//   /posts/{post_id}/comments — the parent id reaches actions and filters as $post_id
$router->resource('photos', PhotosController::class, except: ['update'], middleware: 'throttle:30,1');
```

Attribute routes (register the classes with `$router->controllers([...])`):

```php
use Racin\Routing\Attributes\{Get, Post, Prefix, Middleware};

#[Prefix('/posts', name: 'posts.')]
#[Middleware('auth')]
final class PostController
{
    #[Get('/{id:\d+}', name: 'show')]
    public function show(int $id): array { /* ... */ }

    #[Post('/', middleware: 'throttle:10,1')]
    public function store(Request $request): JsonResponse { /* ... */ }
}
```

Controller method parameters are resolved by **name** from route parameters (with `int`/`float`/`bool`
conversion; a value that cannot be converted gives 404) and by **type** from the container
(`Request`, services, ...). Constructors are autowired too.

Unmatched path → 404, path matched with the wrong method → 405 with `Allow`. `HEAD` uses `GET` routes.

## Requests

```php
$request->input('user.name', 'default');  // body (JSON or form) merged over query string, dot notation
$request->query('page');
$request->string('name');  $request->integer('page', 1);  $request->boolean('active');  $request->array('tags');
$request->only(['name', 'email']);  $request->except(['password']);  $request->has('x');  $request->filled('x');
$request->header('X-Api-Version');  $request->bearerToken();  $request->ip();  $request->path();
$request->file('avatar');            // UploadedFile: isValid(), mimeType(), move($dir)
$request->user();                    // set by the auth middleware
$data = $request->validate(['email' => 'required|email']);  // throws 422
```

A malformed JSON body gives 400. `X-Forwarded-For`/`-Proto` are only trusted from `app.trusted_proxies`.

## Responses

Return arrays, models, paginators or anything `JsonSerializable`. For control:

```php
return new JsonResponse(['data' => $post], 201, ['Location' => "/posts/{$post->id}"]);
return Response::noContent();
abort(403, 'Not your post.');
```

## Rails-style controllers

Controllers that extend `Racin\Http\Controller` get `before_action` filters, strong parameters,
`render()` with serializers and `head()`. Queries stay in models (scopes, relations); the controller
only wires things together.

```php
use Racin\Http\Attributes\{BeforeAction, SkipBeforeAction};

#[BeforeAction('authorizeRequest')]                       // inherited by every subclass
abstract class ApiController extends Racin\Http\Controller { /* authorizeRequest(), checkPermission() ... */ }

#[SkipBeforeAction('authorizeRequest', only: ['index'])]
final class UsersController extends ApiController
{
    private User $user;

    public function __construct()
    {
        // before_action ..., only: [...]  — filters run in the order they are declared
        $this->beforeAction(fn () => $this->checkPermission('users', 'read'), except: ['index']);
        $this->beforeAction('setUser', only: ['show', 'update']);
        $this->beforeAction(fn () => $this->checkTenantAccess($this->user), only: ['show']);
    }

    public function index(): Response
    {
        $users = User::byTenant($this->currentUser()->tenant_id)->with('roles')->paginate(20, $this->page());

        return $this->render(json: $users, eachSerializer: UserSerializer::class, status: 'ok');
    }

    public function show(): Response
    {
        return $this->render(json: $this->user, serializer: UserSerializer::class);
    }

    public function update(): Response
    {
        $this->user->update($this->params()->require('user')->permit('name', 'email', ['tags' => []]));

        return $this->render(json: $this->user, serializer: UserSerializer::class);
    }

    protected function setUser(int $id): void      // route parameters by name, services by type
    {
        $this->user = User::findOrFail($id);
    }
}
```

- **Filters** run before the action: class attributes (parent classes first), then those registered in
  the constructor. A filter stops the request by throwing (`abort(403)`, `findOrFail` → 404, validation
  → 422) or by returning a `Response`. `only:` / `except:` limit them to actions; `#[SkipBeforeAction]`
  or `$this->skipBeforeAction()` turn an inherited one off. Filter methods may be protected or private;
  only public methods declared on the controller are routable actions.
- **`render(json:, serializer:, eachSerializer:, status:, headers:, meta:, root:)`** wraps the value as
  `{"data": ...}`; a `Paginator` adds `"meta"`. `status` takes Rails names (`'created'`,
  `'unprocessable_entity'`, ...) or numbers. `head('no_content')` sends no body.
- **`params()->require('user')`** answers 400 when the key is missing; **`permit()`** keeps only the
  listed keys: scalars by name, `['tags' => []]` for a list of scalars, `['address' => ['city', 'zip']]`
  for nested data.
- `$this->request`, `$this->currentUser()` and `$this->validate([...])` work in actions and filters.

## Serializers

Like ActiveModel::Serializer: which attributes and relations go out, computed fields as methods.

```php
final class OrderSerializer extends Racin\Http\Serializer
{
    protected array $attributes = ['id', 'number', 'status', 'total', 'item_count', 'created_at'];
    protected array $relations = ['user' => UserSerializer::class, 'items' => OrderItemSerializer::class];

    public function itemCount(Order $order): int          // computed attribute "item_count" (camelCase method)
    {
        return count($order->items);
    }
}

OrderSerializer::one($order);                // array (or null)
OrderSerializer::many($orders);              // relations eager loaded for the whole list (no N+1)
OrderSerializer::page($paginator);           // Paginator of arrays
```

`$this->context` holds what the caller passed (`render(context: [...])`; the current user is in
`$this->context['user']`). Dates are output as ISO-8601. Override `relationPaths()` to preload relations
that a computed attribute reads (e.g. `['user']` for a `user_name` field).

## Files & images

`config/filesystems.php` defines disks (`public` → `public/uploads`, served at `APP_URL/uploads`;
`local` → `storage/app`).

```php
$disk = app(Racin\Storage\StorageManager::class)->disk('public');
$disk->put('reports/a.csv', $csv);  $disk->get(...);  $disk->exists(...);  $disk->url('reports/a.csv');  $disk->delete([...]);
storage_url($path);                 // URL on the default disk (null for null)

$stored = app(Racin\Image\ImageUploader::class)->store($request->file('photo'), 'products/7');
// ['path' => 'products/7/<random>.webp', 'thumb_path' => '..._thumb.webp', 'width', 'height', 'size', 'mime']
app(Racin\Image\ImageUploader::class)->delete($stored['path']);   // image + thumbnail
```

Storage paths are relative to the disk root (`..`, drive letters and NUL bytes are rejected). The
uploader decodes the file with GD (needs the `gd` extension), applies the EXIF orientation, rejects
anything that is not JPEG/PNG/GIF/WebP or is larger than 40 megapixels, resizes it (`images.max_width`,
`max_height`), re-encodes it (`images.format`: webp, jpeg or png) and makes a cropped thumbnail; metadata
and anything appended to the file are dropped. Validation rules for uploads: `image` (a real raster image,
never SVG), `mimes:jpg,png` (checks the detected type, not the file name), `max:<kilobytes>`,
`dimensions:min_width=100,max_height=4000`. Multipart requests: `$request->file('photo')`, `hasFile()`,
`allFiles()`; HTML forms can send PATCH/DELETE as POST with a `_method` field.

## Middleware

```php
final class EnsureOwner implements Racin\Http\Middleware
{
    // "owner:post" → $resource = 'post'
    public function handle(Request $request, Closure $next, string $resource = 'post'): Response
    {
        $ownerId = DB::table($resource . 's')->where('id', (int) $request->route('id'))->value('user_id');

        if ($ownerId === null || (int) $ownerId !== $request->user()?->id) {
            throw new Racin\Auth\AuthorizationException();
        }

        return $next($request);
    }
}
```

Register an alias in `config/app.php` → `middleware_aliases` (`'owner' => EnsureOwner::class`) and use
`'owner'` or `'owner:post'` (parameters after `:` separated by `,`). Built in:

| Alias | Class | |
|---|---|---|
| `auth` | `Auth\Middleware\Authenticate` | Bearer JWT (not revoked) → `$request->user()`, else 401 |
| `role:admin,manager` | `Auth\Middleware\RequireRole` | user has one of the roles, else 403 |
| `can:catalog.manage` | `Auth\Middleware\RequirePermission` | user has every listed permission, else 403 |
| `throttle:60,1` | `Http\Middleware\ThrottleRequests` | 60 requests / 1 minute per user (or IP) per route → 429 |
| `cors` | `Http\Middleware\HandleCors` | Configured in `config/cors.php`, handles preflight |
| `locale` | `Http\Middleware\SetLocale` | language from `?lang=` / `Accept-Language`, sets `Content-Language` |

## Validation

```php
$data = Validator::make($input, [
    'email'      => 'required|email|max:255|unique:users,email',
    'age'        => 'nullable|integer|between:13,130',
    'items'      => 'required|array|max:50',
    'items.*.id' => 'required|integer|exists:products,id',
    'code'       => ['required', 'regex:/^[A-Z]{3}-\d+$/', new MyRule()],
], ['email.unique' => 'That email is taken.'])->validate();
```

Fields are optional unless a `required*` rule is present; validation of a field stops at its first
failure; `validate()` returns only fields that had rules.

Rules: `required required_if required_unless required_with required_without present accepted sometimes nullable
string integer numeric boolean array list email url ip ipv4 ipv6 uuid alpha alpha_num alpha_dash date date_format
before after min max between size gt gte lt lte in not_in regex not_regex confirmed same different digits
digits_between json starts_with ends_with unique exists file image mimetypes`.
Custom: implement `Validation\Rule`, pass a closure `fn ($attribute, $value, $fail)`, or `Validator::extend()`.

## Database

```php
use Racin\Database\DB;

DB::table('users')->where('active', 1)->where('age', '>=', 18)->orderByDesc('id')->limit(10)->get();
DB::table('users')->where(fn ($q) => $q->where('a', 1)->orWhere('b', 2))->whereIn('id', [1, 2])->count();
DB::table('posts as p')->join('users as u', 'u.id', '=', 'p.user_id')->select('p.title', 'u.name')->get();
DB::table('users')->paginate(perPage: 20, page: 2);     // {"data": [...], "meta": {...}}
DB::table('users')->insertGetId(['name' => 'Ana']);
DB::table('users')->where('id', 5)->update(['name' => 'B']);   // update/delete without where() throw
DB::table('users')->where('id', 5)->increment('logins');
DB::transaction(function () { /* nested calls use savepoints */ });
DB::connection('other')->select('SELECT * FROM t WHERE id = ?', [5]);
```

All values are bound parameters. Column/table names are validated and quoted; use `DB::raw()`,
`selectRaw()`, `whereRaw()` for SQL expressions (never with user input). Drivers: MySQL/MariaDB,
PostgreSQL (`pgsql`, needs `pdo_pgsql`), SQLite. `where('name', 'ilike', '%ana%')` is case-insensitive on
every driver; `lockForUpdate()->skipLocked()` skips rows other transactions hold.

### Models

```php
final class Post extends Racin\Database\Model
{
    protected string $table = 'posts';            // default: plural snake_case class name
    protected array $fillable = ['title', 'body'];
    protected array $hidden = ['internal_notes'];
    protected array $casts = ['published' => 'bool', 'meta' => 'array', 'published_at' => 'datetime'];
}

$post = Post::create($data);               // only $fillable keys are assigned
$post = Post::findOrFail($id);             // 404 when missing
$posts = Post::where('published', 1)->latest()->paginate(20, $page);
$post->update(['title' => 'New']);         // only dirty columns are written; updated_at maintained
$post->delete();
Post::findBy('slug', 'hello');  Post::firstOrCreate(['slug' => 'x'], ['title' => 'X']);  Post::updateOrCreate([...], [...]);
```

**Relations, scopes, validation and callbacks** (ActiveRecord style):

```php
final class Post extends Model
{
    protected array $rules = [                       // checked on every save → ValidationException (422)
        'title' => 'required|string|max:200',
        'slug' => 'required|alpha_dash|unique:posts,slug,{id}',   // {id} = this record, ignored on update
        'user_id' => 'required|exists:users,id',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }            // posts.user_id
    public function comments(): HasMany { return $this->hasMany(Comment::class)->latest(); } // comments.post_id
    public function cover(): HasOne { return $this->hasOne(Image::class); }
    public function tags(): BelongsToMany { return $this->belongsToMany(Tag::class); }     // pivot "post_tag"

    public function scopePublished(QueryBuilder $query): void { $query->where('published', 1); }
    public function scopeBy(QueryBuilder $query, User $user): void { $query->where('user_id', $user->id); }

    protected function beforeValidation(): void { $this->slug ??= Str::slug($this->title); }
    protected function afterDelete(): void { /* remove files, ... */ }
}

$post->user->name;                                // lazy loaded once, then cached
$post->comments()->where('approved', 1)->count(); // a relation is also a query
Post::published()->by($user)->with('user', 'comments.author', 'tags')->paginate(20);  // eager: one query per relation
$post->load('tags');  $post->tags()->sync([1, 2]);  $post->tags()->attach(3);  $post->comments()->create([...]);
```

Callbacks: `beforeValidation`, `beforeSave`/`afterSave`, `beforeCreate`/`afterCreate`,
`beforeUpdate`/`afterUpdate`, `beforeDelete`/`afterDelete` (a `before*` returning `false` cancels).
Updates only validate the attributes the model has; `save(validate: false)` skips validation. Loaded
relations are included in `toArray()` (unless listed in `$hidden`). Default table names are plural
snake_case (`Person` → `people`, `Category` → `categories`).

### Schema & migrations

Migrations are files in `database/migrations` (run in filename order) returning an anonymous class:

```php
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('posts', function (Blueprint $table) {
            $table->id();                                              // BIGINT auto-increment primary key
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->boolean('published')->default(false)->index();
            $table->enum('state', ['draft', 'live'])->default('draft');
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'title']);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('posts');
    }
};
```

The same blueprint produces correct DDL for MySQL/MariaDB, PostgreSQL and SQLite (booleans are stored as
0/1 everywhere, enums become `CHECK` constraints outside MySQL). `$schema->table('posts', fn ($t) => ...)`
adds or drops columns and indexes. Migrations run inside a transaction on PostgreSQL and SQLite (MySQL
commits DDL implicitly).

```
php racin make:migration create_posts_table --create=posts
php racin migrate [--seed]          php racin migrate:status
php racin migrate:rollback [--step=2]    php racin migrate:fresh [--seed]   (drops ALL tables)
```

All of them refuse to run with `APP_ENV=production` unless `--force` is given.

### Seeders

Classes in `database/seeders` (namespace `Database\Seeders`) extending `Racin\Database\Seeder`;
`php racin db:seed` runs `DatabaseSeeder`, `--class=Name` another one. `$this->call(A::class, B::class)`
chains seeders; constructors get dependencies injected.

```php
final class BlogSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedFromFile(Blog::class, __DIR__ . '/data/blogs.json');                 // skipped while the table has rows
        $this->seedFromFile(Tag::class, __DIR__ . '/data/tags.csv', uniqueBy: 'slug');   // insert or update, re-runnable
    }
}
```

`seedFromFile()` reads a JSON list of objects or a CSV with a header row (empty cells are left out, so
column defaults apply) and saves every row through the model (rules, casts, callbacks) in one
transaction; an invalid row fails with its row number. `php racin g seeder Blog [--count=N]` writes the
seeder and a data file with sample rows built from the model's `$rules`.

## Auth

`php racin key:generate` sets `APP_KEY`, which signs HS256 JWTs.

```php
$token = $app->make(Racin\Auth\Jwt::class)->encode(['sub' => $user->id, 'ver' => $user->token_version]);
```

Clients send `Authorization: Bearer <token>`; the `auth` middleware verifies signature/expiry, rejects
revoked tokens and loads the user through `auth.model` (or a custom `Auth\UserProvider` set as `auth.provider`).

- **Revoking one token:** `$app->make(TokenBlacklist::class)->revoke($request->attribute('token'))` — its
  `jti` is kept in the cache until the token would have expired (use a shared cache, e.g. Redis, in production).
- **Revoking all of a user's tokens:** issue tokens with a `ver` claim equal to the user's `token_version`
  column and increment that column; `ModelUserProvider` rejects tokens with an older version.
- **Roles & permissions:** make the user model implement `Racin\Auth\Authorizable` (`hasRole()`,
  `hasPermission()`), then protect routes with `role:` / `can:` middleware.

Passwords: `Racin\Support\Hash::make()` / `verify()` (bcrypt, cost 12).

## Cache, Redis & logging

```php
$cache = $app->make(Racin\Cache\Cache::class);         // driver: file | redis | array
$stats = $cache->remember('stats', 300, fn () => DB::table('orders')->count());

$redis = $app->make(Racin\Redis\RedisFactory::class)->connection();   // phpredis, config database.redis

logger()->info('Order {id} paid', ['id' => $order->id]);   // storage/logs/racin-YYYY-MM-DD.log
```

The Redis store's `flush()` only deletes keys with its own prefix.

## Queues

```php
final class SendInvoice extends Racin\Queue\Job
{
    public int $tries = 5;                  // then it goes to failed_jobs
    public int|array $backoff = [10, 60];   // seconds between attempts

    public function __construct(public readonly int $orderId) {}   // scalars/arrays only — pass ids

    public function queue(): string { return 'mail'; }

    public function handle(Mailer $mailer): void { /* dependencies injected */ }

    public function failed(Throwable $e): void { /* optional */ }
}

dispatch(new SendInvoice($order->id));            // or $app->make(QueueManager::class)->push($job, delaySeconds)
```

Drivers (`config/queue.php`): `sync` (runs immediately), `database` (`jobs` table, `SKIP LOCKED` for
concurrent workers), `redis`. Payloads are JSON (no `unserialize`); jobs are rebuilt by passing the stored
values to the constructor by name. A job reserved by a worker that crashed runs again after `retry_after`.

```
php racin queue:work [--queue=mail] [--connection=redis] [--once] [--stop-when-empty] [--max-jobs=100] [--memory=128]
php racin queue:failed | queue:retry <id|all> | queue:forget <id> | queue:flush
```

## Mail

```php
$mailer = $app->make(Racin\Mail\Mailer::class);
$message = (new Message())
    ->to('ana@example.com', 'Ana')
    ->subject('Нарачката е примена')                               // UTF-8 subjects/names are encoded
    ->html(Template::render(base_path('resources/mail/order.php'), ['order' => $order]))   // text part generated
    ->attach($pdfPath);

$mailer->send($message);     // now
$mailer->queue($message);    // via the queue (SendQueuedMail, 5 tries)
```

Transports (`config/mail.php`): `smtp` (STARTTLS `tls` or implicit `ssl`, AUTH LOGIN), `log`, `array`.
Templates are plain PHP with `$e($value)` for escaping. `php racin mail:test you@example.com` sends a test.

## Translations

`lang/<locale>/<group>.php` return arrays; `__('messages.welcome', ['name' => 'Ана'])`,
`trans_choice('messages.items', $n)` (`one|many` or `{0} none|{1} one|[2,*] :count`). The `locale`
middleware picks the language per request from `?lang=` or `Accept-Language` among `app.locales`, falling
back to `app.locale`. Validation messages come from `lang/<locale>/validation.php` (rule keys, nested
`min.string` etc., and `attributes` for field names); default HTTP error messages from `http.php`.

## Real-time (WebSockets)

`php racin websocket:serve` runs an RFC 6455 server in plain PHP. The application sends events with

```php
broadcast(['user.' . $order->user_id, 'admin.orders'], 'order.placed', ['id' => $order->id]);
```

(`BROADCAST_DRIVER=websocket`; also `log`, `null`, `array`). Events travel to the server's internal port
(localhost, HMAC-signed with `APP_KEY`); if the server is down they are skipped with a log warning and
retried after 15 s, so requests are never slowed down. Clients authenticate with
`{"event":"authenticate","token":"<jwt>"}` and `{"event":"subscribe","channel":"..."}`; who may join which
channel is defined in `routes/channels.php`, `public.*` channels are open to guests. Allowed browser
origins: `broadcasting.websocket.allowed_origins`.

## Console

```
php racin                    list commands
php racin serve --port=8000  development server
php racin route:list
php racin route:cache | route:clear | config:cache | config:clear | cache:clear
php racin key:generate [--force]
php racin migrate | migrate:status | migrate:rollback | migrate:fresh | make:migration | db:seed
php racin queue:work | queue:failed | queue:retry | queue:forget | queue:flush
php racin websocket:serve [--port=6001]
php racin mail:test <email>
php racin db:import file.sql
php racin make:controller Admin/Report | make:model Post | make:middleware EnsureOwner | make:command SendDigest
```

**Generators** (Rails' `rails generate` / `rails destroy`; `g` and `d` are aliases):

```
php racin g scaffold Post title:string{200}:unique body?:text price:decimal{10,2} published:boolean user:references cover:image
php racin g model Tag name:string:unique [--no-migration]
php racin g controller Reports index monthlySales          # + GET /v1/reports, /v1/reports/monthly-sales
php racin g migration AddSlugToPosts slug:string:unique    # also RemoveXFromY, CreateXTable
php racin g serializer Post                               # attributes read from the table
php racin g seeder Post --count=10                        # seeder + database/seeders/data/posts.json (rows that pass $rules)
php racin g postman Post                                  # CRUD folder (POST/GET/PUT/PATCH/DELETE) in postman/*.postman_collection.json
php racin d scaffold Post                                 # removes what g scaffold created (--force: also a migration that already ran)
```

`scaffold` writes the create-table migration, the model (`$fillable`, `$casts`, `$rules` from the field
types, `belongsTo` for references, image cleanup on delete), the serializer (`<field>_url` and
`<field>_thumb_url` for images), a resource controller in `App\Controllers\Api\V1` (public index/show,
writes need the "<table>.manage" permission, strong params, image upload), a test in `tests/`, and the
`$router->resource(...)` line above `// racin:resources` in `routes/api.php`, a seeder with sample
data (registered in `DatabaseSeeder`) and a Postman folder. Existing files are not
overwritten without `--force`.

Field syntax: `name[?]:type[{args}][:unique|:index]` — `?` means nullable. Types: string{len}, text,
longtext, integer, bigint, smallint, tinyint, decimal{p,s}, float, boolean, date, datetime, json,
enum{a,b,c}, uuid, references (`user:references` → `user_id` + foreign key + relation), image.

Custom commands extend `Racin\Console\Command` and are listed in `config/app.php` → `commands`.

## Service providers

List providers in `config/app.php` → `providers`. `register()` binds services into the container
(`$this->app->singleton(...)`), `boot()` runs after every provider registered.

## Configuration

`config/*.php` files return arrays (`config('app.name')`); use `env()` only inside config files.
Real environment variables override `.env`. `php racin config:cache` merges everything into one file
for production (re-run after every change).

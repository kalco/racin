<?php

declare(strict_types=1);

namespace Racin\Tests\Orm;

use BadMethodCallException;
use PHPUnit\Framework\TestCase;
use Racin\Database\DatabaseManager;
use Racin\Database\Model;
use Racin\Database\QueryBuilder;
use Racin\Database\Relations\BelongsTo;
use Racin\Database\Relations\BelongsToMany;
use Racin\Database\Relations\HasMany;
use Racin\Database\Relations\HasOne;
use Racin\Validation\ValidationException;
use Racin\Validation\Validator;

final class Author extends Model
{
    protected array $fillable = ['name', 'email'];

    protected array $hidden = ['email'];

    protected array $casts = ['id' => 'int'];

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class)->orderBy('title');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(AuthorProfile::class);
    }
}

final class AuthorProfile extends Model
{
    protected bool $timestamps = false;

    protected array $fillable = ['bio'];
}

final class Post extends Model
{
    /** @var list<string> */
    public static array $log = [];

    public static bool $blockSave = false;

    protected array $fillable = ['title', 'slug', 'published', 'author_id'];

    protected array $casts = ['id' => 'int', 'author_id' => 'int', 'published' => 'bool'];

    protected array $rules = [
        'title' => 'required|string|max:50',
        'slug' => 'required|alpha_dash|unique:posts,slug,{id}',
        'author_id' => 'nullable|integer|exists:authors,id',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->orderBy('id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function scopePublished(QueryBuilder $query): void
    {
        $query->where('published', 1);
    }

    public function scopeTitled(QueryBuilder $query, string $term): void
    {
        $query->where('title', 'like', "%{$term}%");
    }

    protected function beforeValidation(): void
    {
        self::$log[] = 'beforeValidation';

        if (($this->slug ?? '') === '' && is_string($this->title)) {
            $this->slug = strtolower(str_replace(' ', '-', $this->title));
        }
    }

    protected function beforeSave(): ?bool
    {
        self::$log[] = 'beforeSave';

        return self::$blockSave ? false : null;
    }

    protected function beforeCreate(): void
    {
        self::$log[] = 'beforeCreate';
    }

    protected function afterCreate(): void
    {
        self::$log[] = 'afterCreate:' . $this->id;
    }

    protected function beforeUpdate(): void
    {
        self::$log[] = 'beforeUpdate';
    }

    protected function afterSave(): void
    {
        self::$log[] = 'afterSave';
    }

    protected function afterDelete(): void
    {
        self::$log[] = 'afterDelete';
    }
}

final class Comment extends Model
{
    protected array $fillable = ['body'];

    protected array $casts = ['id' => 'int', 'post_id' => 'int'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}

final class Tag extends Model
{
    protected bool $timestamps = false;

    protected array $fillable = ['name'];

    protected array $casts = ['id' => 'int'];

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }
}

final class OrmTest extends TestCase
{
    private DatabaseManager $db;

    protected function setUp(): void
    {
        $this->db = new DatabaseManager(['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]]);
        Model::setConnectionResolver($this->db);
        Validator::setConnectionResolver(fn () => $this->db->connection());
        Post::$log = [];
        Post::$blockSave = false;

        $this->db->connection()->unprepared(<<<'SQL'
            CREATE TABLE authors (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL);
            CREATE TABLE author_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INTEGER NOT NULL, bio TEXT NULL);
            CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INTEGER NULL, title TEXT NOT NULL, slug TEXT NOT NULL UNIQUE, published INTEGER NOT NULL DEFAULT 0, created_at TEXT NULL, updated_at TEXT NULL);
            CREATE TABLE comments (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, body TEXT NOT NULL, created_at TEXT NULL, updated_at TEXT NULL);
            CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL);
            CREATE TABLE post_tag (post_id INTEGER NOT NULL, tag_id INTEGER NOT NULL, PRIMARY KEY (post_id, tag_id));
            INSERT INTO authors (id, name, email) VALUES (1, 'Ana', 'ana@example.com'), (2, 'Boris', 'boris@example.com'), (3, 'Cvetan', NULL);
            INSERT INTO author_profiles (author_id, bio) VALUES (1, 'Writes about PHP');
            INSERT INTO posts (id, author_id, title, slug, published) VALUES
                (1, 1, 'Zebra', 'zebra', 1), (2, 1, 'Apple', 'apple', 0), (3, 2, 'Mango', 'mango', 1);
            INSERT INTO comments (post_id, body) VALUES (1, 'first'), (1, 'second'), (3, 'third');
            INSERT INTO tags (id, name) VALUES (1, 'php'), (2, 'sql'), (3, 'news');
            INSERT INTO post_tag (post_id, tag_id) VALUES (1, 1), (1, 2), (3, 2);
            SQL);
    }

    protected function tearDown(): void
    {
        Model::setConnectionResolver(null);
        Validator::setConnectionResolver(null);
    }

    public function testLazyRelations(): void
    {
        $post = Post::findOrFail(3);
        self::assertSame('Boris', $post->author->name);
        self::assertSame(['third'], array_map(static fn (Comment $c): string => $c->body, $post->comments));

        $ana = Author::findOrFail(1);
        self::assertSame(['Apple', 'Zebra'], array_map(static fn (Post $p): string => $p->title, $ana->posts), 'relation constraints apply');
        self::assertSame('Writes about PHP', $ana->profile->bio);
        self::assertNull(Author::findOrFail(2)->profile);
        self::assertSame([], Author::findOrFail(3)->posts);

        // Relations can be queried further.
        self::assertSame(['Zebra'], $ana->posts()->where('published', 1)->pluck('title'));
        self::assertSame(1, $ana->posts()->where('published', 0)->count());
    }

    public function testEagerLoadingUsesOneQueryPerRelation(): void
    {
        $connection = $this->db->connection();
        $connection->enableQueryLog();

        $authors = Author::query()->with('posts.comments', 'profile')->orderBy('id')->get();
        self::assertCount(4, $connection->getQueryLog(), 'authors + posts + comments + profiles');

        $connection->flushQueryLog();
        self::assertSame(['Apple', 'Zebra'], array_map(static fn (Post $p): string => $p->title, $authors[0]->posts));
        self::assertSame(['first', 'second'], array_map(static fn (Comment $c): string => $c->body, $authors[0]->posts[1]->comments));
        self::assertSame([], $authors[2]->posts);
        self::assertNull($authors[1]->profile);
        self::assertSame('Writes about PHP', $authors[0]->profile->bio);
        self::assertSame([], $connection->getQueryLog(), 'no lazy loading after with()');

        // load() on a model that was already fetched, and belongsTo the other way.
        $posts = Post::query()->with('author', 'tags')->orderBy('id')->get();
        self::assertSame(['Ana', 'Ana', 'Boris'], array_map(static fn (Post $p): string => $p->author->name, $posts));
        self::assertSame(['php', 'sql'], array_map(static fn (Tag $t): string => $t->name, $posts[0]->tags));
        self::assertArrayNotHasKey('racin_pivot_parent', $posts[0]->tags[0]->toArray());

        $author = Author::findOrFail(2)->load('posts');
        self::assertTrue($author->relationLoaded('posts'));
        self::assertFalse($author->relationLoaded('profile'));
    }

    public function testToArrayIncludesLoadedRelationsAndRespectsHidden(): void
    {
        $data = Author::query()->with('posts')->where('id', 2)->firstOrFail()->toArray();

        self::assertArrayNotHasKey('email', $data);
        self::assertArrayNotHasKey('profile', $data);
        self::assertSame('Mango', $data['posts'][0]['title']);
        self::assertTrue($data['posts'][0]['published']);
    }

    public function testBelongsToManyAttachDetachSync(): void
    {
        $post = Post::findOrFail(2);
        $post->tags()->attach([1, 3]);
        self::assertSame(['news', 'php'], $this->tagNames($post));

        $post->tags()->detach(1);
        self::assertSame(['news'], $this->tagNames($post));

        $post->tags()->sync([2, 3]);
        self::assertSame(['news', 'sql'], $this->tagNames($post));

        $post->tags()->sync([]);
        self::assertSame([], $this->tagNames($post));
        self::assertSame(['Mango', 'Zebra'], array_map(static fn (Post $p): string => $p->title, Tag::findOrFail(2)->posts()->orderBy('title')->get()));
    }

    public function testHasManyCreateAndBelongsToAssociate(): void
    {
        $comment = Post::findOrFail(2)->comments()->create(['body' => 'hello']);
        self::assertSame(2, $comment->post_id);
        self::assertSame('Apple', $comment->post->title);

        $post = Post::findOrFail(2);
        $post->author()->associate(Author::findOrFail(2));
        $post->save();
        self::assertSame(2, Post::findOrFail(2)->author_id);
    }

    public function testScopes(): void
    {
        self::assertSame(['Mango', 'Zebra'], Post::published()->orderBy('title')->pluck('title'));
        self::assertSame(['Zebra'], Post::published()->titled('ebr')->pluck('title'));
        self::assertSame(1, Post::query()->titled('ppl')->count());

        $this->expectException(BadMethodCallException::class);
        Post::query()->noSuchScope();
    }

    public function testCallbacksRunInOrder(): void
    {
        $post = Post::create(['title' => 'Hello World', 'author_id' => 2]);

        self::assertSame('hello-world', $post->slug, 'beforeValidation filled the slug');
        self::assertSame(['beforeValidation', 'beforeSave', 'beforeCreate', 'afterCreate:' . $post->id, 'afterSave'], Post::$log);

        Post::$log = [];
        $post->update(['title' => 'Hello Again']);
        self::assertSame(['beforeValidation', 'beforeSave', 'beforeUpdate', 'afterSave'], Post::$log);

        Post::$log = [];
        $post->delete();
        self::assertSame(['afterDelete'], Post::$log);
        self::assertNull(Post::find($post->id));
    }

    public function testBeforeCallbackReturningFalseCancelsTheSave(): void
    {
        Post::$blockSave = true;
        $post = new Post(['title' => 'Blocked']);

        self::assertFalse($post->save());
        self::assertFalse($post->exists);
        self::assertSame(0, Post::query()->where('title', 'Blocked')->count());
    }

    public function testModelRulesAreValidatedOnSave(): void
    {
        try {
            Post::create(['title' => str_repeat('x', 60), 'slug' => 'zebra', 'author_id' => 99]);
            self::fail('Expected a validation error.');
        } catch (ValidationException $e) {
            self::assertSame(['title', 'slug', 'author_id'], array_keys($e->errors()));
        }

        // Updates check only the attributes the model has; the record's own slug is not "taken".
        $post = Post::findOrFail(1);
        $post->update(['title' => 'Zebra 2', 'slug' => 'zebra']);
        self::assertSame('Zebra 2', Post::findOrFail(1)->title);

        $this->expectException(ValidationException::class);
        $post->update(['slug' => 'mango']);
    }

    public function testValidationSeesAssignedValuesNotCasts(): void
    {
        $post = new Post(['title' => 'Typed', 'author_id' => 'abc']);

        try {
            $post->save();
            self::fail('Expected a validation error.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('author_id', $e->errors());
        }

        self::assertFalse($post->exists);
    }

    public function testFindByFirstOrCreateAndUpdateOrCreate(): void
    {
        self::assertSame(2, Author::findBy('email', 'boris@example.com')?->id);
        self::assertSame(1, Author::findBy(['name' => 'Ana', 'email' => 'ana@example.com'])?->id);
        self::assertNull(Author::findBy('name', 'Nobody'));

        $existing = Author::firstOrCreate(['name' => 'Ana'], ['email' => 'other@example.com']);
        self::assertSame(1, $existing->id);
        self::assertSame('ana@example.com', $existing->email);

        $created = Author::firstOrCreate(['name' => 'Dana'], ['email' => 'dana@example.com']);
        self::assertTrue($created->exists);
        self::assertSame('dana@example.com', Author::findOrFail($created->id)->email);

        $updated = Author::updateOrCreate(['name' => 'Boris'], ['email' => 'b@example.com']);
        self::assertSame(2, $updated->id);
        self::assertSame('b@example.com', Author::findOrFail(2)->email);
        self::assertSame(5, Author::updateOrCreate(['name' => 'Eva'], ['email' => 'eva@example.com'])->id);
    }

    /**
     * @return list<string>
     */
    private function tagNames(Post $post): array
    {
        $names = array_map(static fn (Tag $t): string => $t->name, $post->tags()->get());
        sort($names);

        return $names;
    }
}

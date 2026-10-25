# Changelog

All notable changes to `roka/racin`. The project follows [Semantic Versioning](https://semver.org).

## 1.2.0 — 2026-10-25

First public release as a Composer package.

### Added
- Rails-style controllers (`Racin\Http\Controller`): `#[BeforeAction]` / `#[SkipBeforeAction]` and
  constructor `beforeAction()` filters, `render(json:, serializer:, eachSerializer:, status:)`, `head()`,
  strong parameters (`params()->require()->permit()`), Rails status names.
- `Router::resource()` with Rails action names (index, create, show, update, destroy) and nested resources.
- Model relations (`belongsTo`, `hasMany`, `hasOne`, `belongsToMany` with `attach`/`detach`/`sync`),
  eager loading (`with()`, `load()`), scopes, lifecycle callbacks, validation `$rules`,
  `findBy`, `firstOrCreate`, `updateOrCreate`.
- Serializers (`Racin\Http\Serializer`) with computed attributes, nested relations and N+1-free lists.
- Storage disks, image uploads (`ImageUploader`: GD re-encoding to WebP, thumbnails, EXIF orientation)
  and the `image`, `mimes`, `dimensions` validation rules.
- Generators: `g scaffold | model | controller | migration | serializer | seeder | postman` and
  `d ...` to undo them.
- `Seeder::seedFromFile()` for JSON/CSV seed files validated through the model.
- `vendor/bin/racin` console entry point.

### Changed
- Throttling resolves the bearer token itself, so limits stay per user when controllers authenticate.
- English plurals: irregular and uncountable words (`person` → `people`).

### Fixed
- `Image::encode()` no longer leaves an output buffer open when the format is unsupported.
- The failed-jobs repository connects to the database only when used (the console works without a database).

## 1.1.0

Migrations, queues (sync, database, Redis), mail, WebSocket broadcasting, roles and permissions,
token revocation, Redis cache, PostgreSQL driver, translations (en, mk).

## 1.0.0

Container, routing, middleware, validation, query builder and models, JWT auth, cache, logging, console.

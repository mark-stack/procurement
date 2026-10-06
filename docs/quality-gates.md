# Quality gates

What CI enforces, what it deliberately does not, and why. `.github/workflows/ci.yml` runs all of it
on every push to `main` and on every pull request.

| Gate | Command | Fails when |
| --- | --- | --- |
| Test suite | `php -d memory_limit=1G vendor/bin/pest` | any test fails |
| Static analysis | `php scripts/phpstan-gate.php` | PHPStan rises above the accepted count below |
| Formatting | not run | never - see [Pint](#pint-is-installed-and-deliberately-not-run) |

Both gates run the same way locally as they do in CI. Neither needs a database: the suite is
configured in `phpunit.xml` against in-memory SQLite.

### The suite needs the frontend built first

`npm ci && npm run build` runs before Pest, and it is not a frontend concern that wandered into the
test job. `public/build` is gitignored, so a fresh checkout has no Vite manifest, and every Inertia
page renders through a Blade root that calls `@vite`. With no manifest that throws, the response
stops being a valid Inertia page, and **116 tests fail** on "Not a valid Inertia response" - an
error with nothing to do with what any of them were testing. It is invisible on a working machine,
where `public/build` has been sitting there since the first `npm run build`.

The side effect is worth having: a frontend build that does not compile now fails CI.

## The PHPStan baseline

PHPStan runs at level 5 over `app/` (`phpstan.neon`), with `--memory-limit=1G` because the default
128M is not enough to finish.

Accepted PHPStan errors: 22

That line is the gate. `scripts/phpstan-gate.php` reads the number from it, so there is one copy of
it and it sits next to the reasoning for it. **A rise fails the build. A drop does not** - it prints
a warning asking for the number to be lowered in the same change, because a baseline left above the
real count is a gate that has quietly stopped gating.

PHPStan's own baseline file would have been the conventional choice and was turned down twice over.
It suppresses the errors it records, so the accepted list stops being read and the concession
becomes permanent and invisible; and with `reportUnmatchedIgnoredErrors` on by default, it fails the
build of whoever *fixes* one. The count gate prints all 22 on every run instead.

### What the 22 are, and why they are accepted

Thirteen of the twenty-two are one tooling limitation, not thirteen problems:

| Count | Identifier | What it is |
| --- | --- | --- |
| 13 | `method.notFound` | Larastan loses the concrete model and reports a relation as an undefined method on `Illuminate\Database\Eloquent\Model`. Calls like `->projects()` and `->projectApprovalFlags()` exist and are exercised by the suite. In `AttachPiecesToOrder`, `AttachPiecesToQuote`, `DetachPiecesFromOrder`, `KanbanFormatter` (×3), `PastProjectsController`, `HandleInertiaRequests`, `Product`, `PrerequisiteConditions` (×2), `BatchService` |
| 3 | `argument.unresolvableType` | The same inference loss reaching `array_values` and `usort`, which then have no element type to check. `Batch:605`, `BatchService:43` (×2) |
| 1 | `return.type` | `Offcut::batchFrom()` is declared `Batch` and Larastan widens the query result to `Model`. Same cause |

Those seventeen are all the generic-model inference gap. The honest fixes are annotations and
`@return` docblocks rather than behaviour changes, which is why they are accepted rather than
patched around with `ignoreErrors` - an ignore pattern would hide the real ones underneath.

The remaining five are specific, and each is a small real thing nobody has got to yet:

| Where | Identifier | Why it is accepted for now |
| --- | --- | --- |
| `TestingFormatter:158` | `property.nonObject` | Reads `->value` off `grade`, a plain `text` column with no cast. It works only while the attribute still holds the enum it was assigned in the same request, which is what the sample BOMs do. It would return null against a quote reloaded from the database. Test-support code, reached only from `tests/Pest.php` - so a latent defect in a fixture path, not in anything a customer runs |
| `Project:686` | `argument.type` | `orderBy('created_at', 'DESC')` - uppercase where the signature now says `'asc'\|'desc'`. Correct SQL, wrong case. A one-character fix nobody has made |
| `Project:630` | `larastan.noUnnecessaryCollectionCall` | A `pluck` done in PHP that the database could have done. Performance advice on a small collection |
| `PrerequisiteConditions:61` | `booleanAnd.leftAlwaysTrue` | One of six `$condition_n` flags that PHPStan can prove is always true. Worth looking at, because a prerequisite that cannot fail is a check that is not checking |
| `DataClassificationService:756` | `booleanOr.rightAlwaysFalse` | As above, on the right of an `\|\|` |

The sixth used to be a `nullsafe.neverNull` on `Batch` - a `?->name ?? ...` whose left side could not
be null - and it went with the code around it. That is why the line above now reads 22; the count had
been left at 23 after the fix, which is the drift the gate's own warning exists to catch.

None of the five changes what the application does today. All five are worth clearing, and clearing
any of them should lower the number on the `Accepted PHPStan errors:` line in the same change.

## Pint is installed, and deliberately not run

`laravel/pint` is in `require-dev` and there is no `pint.json`. It is not in CI, and it should not
be added to CI, because on the default preset it rewrites 283 files:

| Files touched | Fixer | What it would do |
| --- | --- | --- |
| 253 | `single_line_comment_spacing` | Rewrite `//Comment` to `// Comment` across the entire codebase |
| 83 | `braces_position` | Move brace placement |
| 74 | `not_operator_with_successor_space` | `!$x` to `! $x` |
| 71 | `unary_operator_spaces` | |
| 56 | `single_quote` | |
| 56 | `fully_qualified_strict_types` | |
| 44 | `ordered_imports` | |

`//Comment` with no space is the house style, used consistently and on purpose, and
`single_line_comment_spacing` alone would touch 253 files to undo it. The rest is the usual preset
churn. A reformat of that size would bury every real change in the history it passed through and
make `git blame` useless on the files that most need it.

If formatting is ever worth enforcing here, the way in is a `pint.json` that disables
`single_line_comment_spacing` and whatever else contradicts the house style, applied in one
deliberate commit that nothing else shares - not a CI gate bolted onto the current preset. Until
somebody decides to do that, running Pint is a mistake, and this paragraph is here so that it is a
decision on the record rather than an oversight anybody is free to "fix".

# rasuvaeff/property-testing-core

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/property-testing-core/v)](https://packagist.org/packages/rasuvaeff/property-testing-core)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/property-testing-core/downloads)](https://packagist.org/packages/rasuvaeff/property-testing-core)
[![Build](https://github.com/rasuvaeff/property-testing-core/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/property-testing-core/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/property-testing-core/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/property-testing-core/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/property-testing-core/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/property-testing-core/php)](https://packagist.org/packages/rasuvaeff/property-testing-core)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

[English version](README.md)

Framework-agnostic **движок** property-based тестирования для PHP 8.3+:
генераторы с интегрированным shrinking, структурный runner, регрессионный
корпус, события жизненного цикла и stateful/model-based тестирование — без
зависимости от какого-либо тестового фреймворка. Сотни случайных входов на
проверку, поиск падающего и сжатие его до минимального контрпримера, который
реально читается.

> Работаете с AI-ассистентом? [llms.txt](llms.txt) содержит компактный API-справочник для модели. Если проект использует [`llm/skills`](https://github.com/roxblnfk/skills), скилл [`rasuvaeff-property-testing-core`](resources/skills/rasuvaeff-property-testing-core/SKILL.md) автоматически синхронизируется в `.agents/skills/` при `composer require` — он построен вокруг решений (какой генератор, какой механизм фазы выбрать), а полный синтаксис оставляет здесь. Чтобы зеркало скилла появилось в `.claude/skills/` или `.cursor/skills/` (один набор файлов, на уровне ОС — junction/symlink), добавьте `skills.json` в корень проекта: `{"target": ".agents/skills", "aliases": [".claude/skills", ".cursor/skills"]}` — либо запустите `composer skills:init` для интерактивного мастера.

## Семейство property-testing

| Пакет | Когда использовать |
|---|---|
| **`rasuvaeff/property-testing-core`** (этот пакет) | Вы управляете движком сами: собственный harness, CI-страж, CLI-проверка или адаптер другого фреймворка |
| [`rasuvaeff/property-testing-testo`](https://github.com/rasuvaeff/property-testing-testo) | Вы тестируете с [Testo](https://github.com/php-testo/testo) — drop-in замена замороженного `rasuvaeff/property-testing` с тем же атрибутом `#[Property]` |
| [`rasuvaeff/property-testing-phpunit`](https://github.com/rasuvaeff/property-testing-phpunit) | Вы тестируете с PHPUnit — trait `PropertyTesting` с fluent-API `forAll()->check()` |
| [`rasuvaeff/property-testing-names`](https://github.com/rasuvaeff/property-testing-names) | На входе люди: формы, профили, авторизация, валидаторы, отчёты — `Names::first()`/`last()`/`middle()` берут отдельные части независимо, `full()`/`person()` держат все части согласованными по одному полу; встроенные наборы `en` и `ru`, shrink к самой короткой записи |

> **Внимание:** пакет объявляет `conflict` с замороженным
> `rasuvaeff/property-testing` (2.x) — оба поставляют классы в namespace
> `Rasuvaeff\PropertyTesting`, поэтому Composer откажется ставить их вместе.
> Мигрируете с 2.x? Замените dev-зависимость на адаптер вашего фреймворка;
> импорты в коде не меняются. Полное руководство —
> [MIGRATION.md](MIGRATION.md) (на английском): две composer-команды и ни
> одной правки PHP для проектов на Testo плюс пути для своего harness и
> PHPUnit.

## Требования

- PHP 8.3+
- `ext-mbstring`
- `ext-random`

## Установка

```bash
composer require --dev rasuvaeff/property-testing-core
```

У движка нет зависимости от тестового фреймворка: вы передаёте ему определение
property и executor, он возвращает структурный результат. Он никогда не читает
переменные окружения, не печатает, не вызывает exit и не бросает исключение,
чтобы сообщить исход property.

## Использование

Соберите `PropertyDefinition` (генераторы по именам параметров плюс
`PropertyConfig`), выполните тело через `TrialExecutor`
(`CallableTrialExecutor` адаптирует обычное замыкание) и разберите
`PropertyResult`, который вернёт `PropertyRunner`:

```php
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;

$definition = new PropertyDefinition(
    id: 'demo::everyIntStaysBelowHundred',
    name: 'everyIntStaysBelowHundred',
    generators: ['value' => Gen::intBetween(0, 10_000)],
    parameterNames: ['value'],
    config: new PropertyConfig(runs: 200, seed: 42),
);

$result = (new PropertyRunner())->run($definition, new CallableTrialExecutor(
    static function (int $value): void {
        if ($value >= 100) {
            throw new RuntimeException(sprintf('%d is not below 100', $value));
        }
    },
));

if ($result instanceof Falsified) {
    $example = $result->counterExample();
    // $example->seed, $example->originalArguments, $example->shrunkArguments, ...
    fwrite(STDERR, $result->failure()->getMessage());
}
```

Сообщение об ошибке рендерит контрпример:

```text
Property falsified after 0 successful run(s); seed=42
  Original: value=54
  Shrunk:   value=100 (3 shrink step(s), 11 trial(s))
  Changed:  value=54 -> 100
  Failure:  100 is not below 100
```

Строка `Changed:` показывает разницу между исходным и сжатым контрпримером —
аргументы, которые shrinker не тронул, опущены. `trial(s)` — все кандидаты
shrinker'а (принятые и отвергнутые); `shrink step(s)` — только принятые.
Точное воспроизведение прогона — запиньте seed из отчёта в `PropertyConfig`.

Полный исполняемый скрипт —
[`examples/standalone_runner.php`](examples/standalone_runner.php).

### Шов executor

`TrialExecutor` — граница между движком и тем, что исполняет тело property.
Каждый вызов `execute($arguments)` возвращает `TrialOutcome` — `passed()`,
`failed($throwable)` или `discarded()`:

- `CallableTrialExecutor` — standalone-executor: нормальный возврат — pass,
  `Assume::that()` — discard, любой другой throwable — failure.
- Адаптеры фреймворков реализуют свои (Testo мапит `TestResult`, PHPUnit —
  assertion-исключения) — цикл run/shrink никогда не узнаёт о типах
  фреймворка.

### Структурные результаты

`PropertyRunner::run()` возвращает один из закрытой иерархии `PropertyResult` —
невозможные комбинации данных непредставимы, а каждый непроходной исход несёт
собственный тип исключения движка с устоявшимся форматом сообщения:

| Результат | Значение | Несёт |
|---|---|---|
| `Passed` | Все проверки завершились, все coverage-требования выполнены | `RunStatistics` |
| `Falsified` | Случайный прогон упал; контрпример сжат | `PropertyViolationException` → `CounterExample` |
| `GaveUp` | Бюджет discard'ов исчерпан до `runs` проверок | `GaveUpException`, `RunStatistics` |
| `CoverageFailed` | Все прогоны прошли, но требование `Classify::cover()` не выполнено | `CoverageViolationException`, `RunStatistics` |
| `DeadlineExceeded` | Один прогон превысил `timeoutMs` | `DeadlineExceededException` |
| `TimeBudgetExceeded` | Случайная фаза превысила `budgetMs` | `TimeBudgetExceededException`, `RunStatistics` |
| `GenerationFailed` | Генератор не смог произвести валидное значение | `GenerationExhausted` |
| `ExampleFailed` | Упал явный example (examples идут первыми, без shrink) | `ExampleViolationException` |
| `RegressionFailed` | Записанная corpus-запись всё ещё падает | `RegressionViolationException` |
| `PathFailed` | Прогон фальсифицировал property, но не смог пройти закреплённый `path` | `PathViolationException` |

Ошибки конфигурации (`runs < 1`, отсутствующий генератор, несовпадение имён
параметров) остаются исключениями — это ошибки программиста, а не вердикт о
property.

`RunStatistics` отдаёт сырые счётчики фазы (attempts, discards, checks,
классификация по меткам) — distribution-отчёт и предупреждение о discard'ах
печатает адаптер; движок никогда не форматирует вывод фреймворка.

Сериализация: каждый результат переживает нативный `serialize()`, когда trace
не захватывает значения аргументов (`zend.exception_ignore_args=1`);
переносимый машинный формат — `CounterExample::toArray()` / `toJson()`.

### Генераторы

Все фабрики живут на фасаде `Gen`; каждая возвращает реализацию
`ArbitraryInterface`, чей `generate(Random)` отдаёт `Shrinkable` — значение
плюс ленивое дерево меньших кандидатов, поэтому преобразованные генераторы
сжимаются через исходный домен.

| Фабрика | Производит | Сжимается |
|---|---|---|
| `Gen::int()` | `IntArbitrary`, `PHP_INT_MIN..PHP_INT_MAX` | к `0` |
| `Gen::intBetween($min, $max)` | `IntArbitrary`, `[$min, $max]` | к `0`, в пределах диапазона |
| `Gen::intPositive()` | `IntArbitrary`, `1..PHP_INT_MAX` | к `1` |
| `Gen::float()` | `FloatArbitrary`, `[0.0, 1.0)` | к `0.0` |
| `Gen::floatBetween($min, $max)` | `FloatArbitrary`, `[$min, $max]` | к `0.0`, в пределах диапазона |
| `Gen::bool()` | `BoolArbitrary`, `true` / `false` | `true` -> `false` |
| `Gen::string()` | `StringArbitrary`, Unicode, длина 0..100 | к `''`, затем по длине, затем каждый символ к `a` |
| `Gen::stringAscii()` | `StringArbitrary`, печатный ASCII, длина 0..100 | к `''`, затем по длине, затем каждый символ к `a` |
| `Gen::stringOf($min, $max)` | `StringArbitrary`, Unicode, ограниченная длина | к `''`, затем по длине, затем каждый символ к `a` |
| `Gen::stringFrom($alphabet, $min, $max)` | `CharsetStringArbitrary`, символы из фиксированного алфавита (multibyte OK) | к `''`, затем по длине, затем каждый символ к первому символу алфавита |
| `Gen::bytes($min, $max)` | `BytesArbitrary`, сырые байтовые строки (байты 0..255) | к `''`, затем по длине, затем каждый байт к `"\x00"` |
| `Gen::arrayOf($element, $min, $max)` | `ArrayArbitrary`, списки из `$element`, размер 0..100 по умолчанию | к `[]`, затем по длине, затем каждый элемент |
| `Gen::nonEmptyArrayOf($element, $max)` | `ArrayArbitrary`, непустые списки | по длине (не ниже 1), затем каждый элемент |
| `Gen::uniqueArrayOf($element, $min, $max)` | `UniqueArrayArbitrary`, списки попарно различных элементов | как `arrayOf`, но кандидаты, совпадающие с другим элементом, пропускаются |
| `Gen::subset($values, $min, $max)` | `SubsetArbitrary`, подмножества фиксированного упорядоченного множества — различные члены `$values` в исходном порядке; дубликаты в источнике отвергаются | сначала размер (к пустому множеству), затем каждый элемент к более ранним позициям источника — минимальное подмножество — короткий префикс |
| `Gen::dictOf($key, $value, $min, $max)` | `DictionaryArbitrary`, map'ы с различными ключами из `$key` (int/string) и значениями из `$value`, размер 0..100 по умолчанию | к `[]`, затем по размеру, затем каждое значение (ключи фиксированы) |
| `Gen::record($shape)` | `RecordArbitrary`, map фиксированной формы `['field' => $arb, ...]` | каждое поле через свой arbitrary, набор ключей фиксирован |
| `Gen::elements($array)` | `OneOfArbitrary`, одно значение из массива (массивная форма `oneOf`) | к раньше перечисленным различным значениям |
| `Gen::enum(SomeEnum::class)` | `OneOfArbitrary` по case'ам enum'а | к раньше объявленным case'ам (объявляйте простые первыми) |
| `Gen::constant($value)` | `ConstantArbitrary`, всегда `$value` | не сжимается |
| `Gen::char()` | `StringArbitrary`, один печатный ASCII-символ | к `a` |
| `Gen::uuid()` | `UuidArbitrary`, RFC 4122 v4 UUID-строки | не сжимается |
| `Gen::datetime($min, $max)` | `DateTimeArbitrary`, UTC `DateTimeImmutable`, timestamp в `[$min, $max]` | к Unix-эпохе, в пределах диапазона |
| `Gen::floatSpecial()` | `OneOfArbitrary` по `NAN`, `±INF`, `-0.0` и краям представления float | к раньше перечисленным special-значениям |
| `Gen::intRange($min, $max)` | `FlatMappedArbitrary`, упорядоченные пары `[lo, hi]` с `lo <= hi` | обе границы сжимаются, порядок всегда сохраняется |
| `Gen::recursive($leaf, $wrap, $maxDepth)` | ограниченные рекурсивные структуры: `$wrap` поднимает arbitrary предыдущего уровня | внутри породившей значение ветви |
| `Gen::oneOf(...$values)` | `OneOfArbitrary`, одно из перечисленных значений | к раньше перечисленным различным значениям (простые — первыми) |
| `Gen::nullable($inner)` | `NullableArbitrary`, `null` или значение `$inner` | предпочитает `null`, затем внутреннее дерево |
| `Gen::map($inner, $fn)` | `MappedArbitrary`, `$inner`, преобразованный `$fn` | через внутреннее дерево, с повторным применением `$fn` |
| `Gen::flatMap($inner, $fn)` | `FlatMappedArbitrary`, зависимый генератор из `$fn($innerValue)` | сначала исходное значение (зависимое регенерируется), затем зависимое дерево |
| `Gen::filter($inner, $predicate)` | `FilteredArbitrary`, значения `$inner`, удовлетворяющие `$predicate` (после 100 отвергнутых draw бросает `GenerationExhausted` — никогда не отдаёт значение вне домена) | внутреннее дерево с отсечением кандидатов, не проходящих предикат |
| `Gen::tuple(...$elements)` | `TupleArbitrary`, кортеж фиксированной арности | каждая позиция через свой элемент, арность фиксирована |
| `Gen::frequency($pairs)` | `FrequencyArbitrary`, взвешенный выбор по парам `[вес, arbitrary]` | внутри породившей значение ветви |
| `Gen::ipv4()` | IPv4-строки в точечной нотации | каждый октет к `0` |
| `Gen::ipv6()` | IPv6-адреса в канонической текстовой форме RFC 5952 (нижний регистр, без ведущих нулей, самый длинный прогон нулевых групп сжат в `::`) | каждая группа к `0`, в пределе `::` |
| `Gen::email()` | адреса `local@label.tld` | к кратчайшим local/label и первому TLD |
| `Gen::url()` | URL `http(s)://host.tld[/path]` | к `http://a.com` |
| `Gen::json($maxDepth)` | JSON-кодируемое значение (null/bool/int/float/string/list/object) | внутри порождённой структуры |
| `Gen::jsonString($maxDepth)` | `json_encode`-текст `Gen::json()` | через дерево значения |
| `Gen::regex($pattern)` / `Gen::stringMatching($pattern)` | строки, соответствующие подмножеству regex (компилируется в комбинаторы) | более короткие/простые совпадения (через скомпилированные деревья) |
| `Gen::commands($initialModel, $commandGenerators, $min, $max)` | `CommandSequenceArbitrary`, валидные последовательности команд для stateful-тестирования | сбрасывает блоки команд, затем упрощает каждую |
| `Gen::swarm($choiceGenerator)` | `SwarmArbitrary`, swarm-тестирование: каждый случай видит лишь непустое подмножество вариантов обёрнутого генератора выбора (`oneOf`, `elements`, `frequency`, `commands`) | внутри подмножества, из которого случай получился, — обратно до полного алфавита не расширяется |
| `Gen::forClass($class, $overrides)` | `ClassArbitrary`, экземпляры по тому, что объявляет конструктор: psalm-тип из `@param`, если он есть (`int<0, 100>`, `non-empty-string`, `list<T>`, `'a'\|'b'`), иначе нативный; всё, что прочитать нельзя, — исключение, а не догадка | через сгенерированные аргументы, пересобирая экземпляр |
| `Gen::forParameters($function, $overrides)` | не arbitrary, а карта: `array<string, ArbitraryInterface>` для параметров `ReflectionFunctionAbstract` (метода или кложуры), по именам в порядке сигнатуры — правила `forClass`, применённые к любой сигнатуре; overrides могут быть частичными, остальное достраивается; всё нечитаемое — исключение с именем функции и параметра | каждая запись shrink'ается через свой генератор |

Числовые генераторы (`int*`, `float*`) **boundary-biased**: примерно каждый
пятый draw возвращает краевое значение диапазона (`0`, `±1`, `min`, `max` для
int; `0.0` или `min` для float), где кучкуются баги, вместо равномерного.
Shrinking это не затрагивает. Когда края — это ровно то, чего property
не может использовать (тело отбрасывает `0`, конец диапазона нарушает
предусловие), `edgeCases: EdgeCases::None` генерирует равномерно, вместо того
чтобы тратить каждый пятый прогон на значение, которое будет выброшено.

Размерные генераторы гарантируют **минимум**: `uniqueArrayOf`/`dictOf`
(различные элементы/ключи) и `commands` (применимые шаги) могут не добрать
*выпавший* размер, когда пространство значений исчерпано, но никогда не
опускаются ниже `$min` — недостижимый минимум бросает `GenerationExhausted`.

`Random` оборачивает объектный движок MT19937: два экземпляра с одним seed
дают идентичные последовательности независимо от других random-вызовов в
процессе — именно это делает seed из отчёта воспроизводимым. Не используйте
сгенерированные значения для криптографии.

### Swarm-тестирование (`Gen::swarm`)

Равномерная выборка из полного алфавита делает все случаи похожими друг на
друга: сотня значений из `oneOf('push', 'pop', 'flush')` почти наверняка содержит
все три, поэтому баги, которым нужно *отсутствие* операции, практически
недостижимы. `Gen::swarm()` ограничивает генератор выбора случайным непустым
подмножеством его вариантов на каждый сгенерированный случай — Groce et al.,
*Swarm Testing* (ISSTA 2012):

```php
Gen::swarm(Gen::oneOf('push', 'pop', 'flush'));   // в одном случае доступны, скажем, только 'pop' и 'flush'
Gen::swarm(Gen::commands($model, $commands));   // одна последовательность использует подмножество команд
```

Принимает генераторы выбора — `Gen::oneOf()`, `Gen::elements()`,
`Gen::frequency()`, `Gen::commands()` — и любой ваш `Swarmable`; всё остальное
отвергается исключением. Выжившие ветви `frequency` сохраняют свои веса: ветвь,
которая была вдвое вероятнее соседней, такой и остаётся.

Shrinking не выходит за подмножество, из которого получился случай:
контрпример, найденный без `flush`, никогда не зашринкается в содержащий `flush`, —
именно это и позволяет такой находке воспроизводиться. Два следствия, которые
стоит знать:

- подмножество берётся один раз на сгенерированное значение, поэтому
  оборачивать нужно тот генератор, чью область вы имеете в виду.
  `swarm(commands(...))` ограничивает всю последовательность;
  `arrayOf(swarm(oneOf(...)))` перевыбирает подмножество на каждый элемент —
  это шум, а не swarm-тестирование;
- контрпример сообщает значение, а не подмножество, из которого оно взято.
  Воспроизведение по seed восстанавливает и то и другое.

Swarm над `Gen::commands()` с ненулевым `$minLength` может оставить случай без
применимых команд; это бросает `GenerationExhausted` — ровно так же, как
неограниченный генератор, которого «заморила» модель.

### Зависимые генераторы (`flatMap`)

Когда домен одного входа зависит от другого — список плюс валидный индекс в
него, размер плюс payload этого размера — `Gen::flatMap()` передаёт каждое
сгенерированное значение в замыкание, возвращающее arbitrary финального
значения. В отличие от `Assume::that()`, прогоны не отбрасываются, и
сжимаются оба уровня:

```php
Gen::flatMap(
    Gen::nonEmptyArrayOf(Gen::int()),
    static fn(array $items): ArbitraryInterface => Gen::tuple(
        Gen::constant($items),
        Gen::intBetween(0, count($items) - 1), // всегда валидный индекс
    ),
);
```

### Draw внутри тела (`Gen::draw`)

Когда несколько зависимых значений делают вложенный `flatMap` громоздким,
берите их прямо в теле property через `Gen::draw()` — валидно только пока
runner исполняет тело (иначе бросает). Взятые значения записываются на
replay-ленту, сжимаются как дополнительные параметры и попадают в контрпример
как `draw#1`, `draw#2`, ... При наличии draw принятые shrink-шаги ограничены
(1000 по умолчанию; явный `maxShrinks` побеждает) — это гарантирует
завершение.

### Отбрасывание прогонов (`Assume`)

`Assume::that($condition)` отбрасывает текущую попытку, когда предусловие не
выполнено — попытка не считается ни падением, ни успешной проверкой, а `runs`
по-прежнему означает успешные проверки. Повторы ограничены `maxDiscards`
(по умолчанию `runs * 10`) со структурным `GaveUpException` при превышении.
Конструируйте валидные входы (`flatMap`/`draw`) вместо массового отбрасывания.

### Распределение (`Classify`)

`Classify::label()` / `Classify::when()` считают метки по прогонам;
`Classify::cover($condition, $label, $minPercent)` превращает подсчёт в жёсткое
требование — прошедшая property с недобором метки падает как `CoverageFailed`.
Счётчики возвращаются в `RunStatistics::$classifications`; печать
distribution-отчёта — работа адаптера.

То же содержимое доступно как данные, без разбора печатной строки:
`PropertyFinished::$distribution` несёт `DistributionReport` — каждую метку как
`LabelShare` (счётчик, доля и порог `cover()`, с которым она была
зарегистрирована), `discardPercent()`, `unmetRequirements()` и `toArray()` для
телеметрии. Два знаменателя, разведённые по именам: доли меток считаются от
успешных проверок (discard их не разбавляет), доля discard'ов — от попыток.
Метка, которую потребовали и ни разу не получили, попадает в отчёт со
счётчиком 0, а не исчезает; `coverageAssessed` равен false, когда прогон
закончился до завершения цикла проверок, — отчёт не изображает вердикт,
которого не было. У фальсифицированного прогона распределения нет: он
останавливается на контрпримере.

### Конфигурация

`PropertyConfig` несёт все ручки движка — runner не читает окружение:

| Поле | Default | Значение |
|---|---|---|
| `runs` | 100 | Успешных проверок до завершения (discard'ы не считаются) |
| `seed` | `null` | Seed случайной фазы; null — случайный (попадает в отчёт об ошибке) |
| `maxShrinks` | `null` | Лимит принятых shrink-шагов; 0 отключает shrinking |
| `maxDiscards` | `null` | Бюджет discard'ов; null = `runs * 10` |
| `timeoutMs` | `null` | Wall-clock дедлайн одного прогона → `DeadlineExceeded` |
| `budgetMs` | `null` | Wall-clock бюджет всей случайной фазы → `TimeBudgetExceeded` |
| `shrink` | `null` | `ShrinkMode::Off` отдаёт контрпример как сгенерирован; null = `Full` |
| `shrinkBudgetMs` | `null` | Wall-clock бюджет спуска; включает `ShrinkMode::Bounded` |
| `phases` | `null` | Выполняемые фазы (`Phase::Examples`/`Corpus`/`Random`/`Shrink`); null — все |
| `derandomize` | `false` | Выводить незаданный seed из id property, а не тянуть случайный |
| `edgeCases` | `EdgeCases::Mixin` | `None` выключает граничное смещение числовых генераторов — для property, которым края стоят только прогонов |
| `path` | `null` | Воспроизвести записанный спуск вместо повторного поиска; требует явный `seed` |

### Дерандомизированные прогоны

Незаданный seed берётся случайно, поэтому property, падающая на одном входе из
пятидесяти, в CI падает через раз. Корпус это лечит — но только *после* того,
как первое падение записано. `derandomize: true` закрывает вторую сторону этого
момента:

```php
new PropertyConfig(derandomize: true);   // один и тот же id всегда выбирает одни и те же входы
```

Seed становится чистой функцией от id property: найденный локально баг
воспроизводится в CI, не дожидаясь записи в корпус, а у проходящей property
распределение входов стабильно — именно это делает метрики распределения
сравнимыми между коммитами. Явный `seed` всегда побеждает флаг. Отображение
seed→значения не меняется: меняется только то, какой seed выбирает прогон, а не
то, что этот seed порождает.

### Воспроизведение shrink-пути

Основная работа спуска уходит на отвергнутые кандидаты: самая маленькая
целочисленная property самого движка принимает девять шагов, испытав тридцать
девять кандидатов. Принятые шаги — последняя строка сообщения о провале, и то
же значение лежит на контрпримере, поэтому повторный прогон может пройти по
ним, а не искать их заново:

```text
  Failure:  value>50
  Path:     value:2/value:2/value:4/value:4
```

```php
$counterExample->path;                    // 'value:2/value:2/value:4/value:4'

new PropertyConfig(seed: 1, path: 'value:2/value:2/value:4/value:4');
```

Прогон, который ничего не зашринкал, пути не имеет — строка не печатается
пустой, а отсутствует.

Шаг называет узел — параметр или in-body draw под псевдоименем `draw#N` — и
номер принятого кандидата в перечислении shrink'ов этого узла. Воспроизведение
выполняет тело один раз на шаг, а не один раз на кандидата. Случайную фазу оно
не пропускает: чтобы дойти до упавшего прогона, нужно выполнить предыдущие —
тело может тянуть случайность через `Gen::draw()`, а discard'ы зависят от тела.

Путь — отладочный инструмент, а не фикстура. Его шаги — индексы кандидатов
shrink'а, поэтому правка генератора его осиротит; для этого существует
регрессионный корпус. Переставший применяться путь сообщается отдельным исходом
(`PathFailed`) с указанием сломавшегося шага и никогда не поглощается свежим
поиском: тихий поиск вернул бы контрпример, неотличимый от удачного
воспроизведения. Конфигурации, в которых путь стал бы no-op — без явного seed,
без фазы `Random` или `Shrink`, с выключенным shrinking, с wall-clock бюджетом
спуска, с `maxShrinks` меньше длины пути, с некорректным путём — отвергаются в
конструкторе.

### Режимы shrinking и фазы

`maxShrinks` ограничивает *принятые* шаги, но цена спуска — в *испытанных*
кандидатах: на больших коллекциях это легко дороже самой случайной фазы,
которая нашла падение. Две ручки ограничивают его с другой стороны:

```php
new PropertyConfig(shrink: ShrinkMode::Off);   // отдать контрпример как сгенерирован
new PropertyConfig(shrinkBudgetMs: 500);       // спускаться не дольше 500 мс, оставить лучшее
```

Бюджет спуска — единственная ручка пакета, которая стоит детерминизма: как
далеко уйдёт спуск, зависит от того, сколько работает тело, поэтому один и тот
же seed на быстрой и медленной машине минимизируется по-разному. Она отвечает
на «спуск завис», а не на «воспроизведи точно» — для второго пиньте seed или
полагайтесь на корпус.

Фазы прогона — множество, а не жёсткая последовательность:

```php
new PropertyConfig(phases: [Phase::Examples, Phase::Corpus]);  // быстрый гейт на PR
new PropertyConfig();                                          // все фазы (по умолчанию)
```

| Правило | Поведение |
|---|---|
| Пустое множество фаз | `InvalidArgumentException` — прогону без фаз не о чем отчитываться |
| Множество фаз с чем-либо кроме `Phase` | `InvalidArgumentException` — нераспознанная стадия просто не выполнится, и свойство отчитается зелёным, ничего не проверив |
| Множество без `Shrink` | Ровно `ShrinkMode::Off`; из двух ручек всегда побеждает более строгая |
| `Phase::Corpus` | Гейтит только **replay** корпуса и складывается с `replayRegressions` по И; новая фальсификация всё равно записывается |
| Множество без `Random` | Ничего не генерируется: честные нули (`attempts: 0`, `checks: 0`), требования покрытия отбрасываются, а не считаются от пустого знаменателя. Результат — `Passed`, если включённые более ранние фазы прошли: закреплённый пример или запись корпуса, которые падают, по-прежнему отчитываются своим отказом |

`PropertyDefinition` добавляет идентичность (`id` ключует события и корпус),
отображаемое `name`, `generators`, `parameterNames`, фиксированные `examples`
(позиционные кортежи, выполняются до случайной фазы, никогда не сжимаются) и
`replayRegressions` (адаптеры выключают его, когда property пинит свой seed).

Переменные окружения `PROPERTY_RUNS` / `PROPERTY_SEED` / `PROPERTY_VERBOSE` /
`PROPERTY_DB` — конвенции **адаптеров**: адаптеры разрешают их в
`PropertyConfig` и `Corpus`. Единственный helper движка —
`FilesystemCorpus::fromEnv()`, читающий `PROPERTY_DB`, когда его вызываете
*вы*.

### Регрессионный корпус

Передайте `Corpus` в `PropertyRunner::run()` — каждая фальсификация
записывается; записанные падения реплеятся **до** случайной фазы: всё ещё
падающее репортится сразу (`RegressionFailed` для values-записи), переставшее
падать — вычищается. Без аргумента corpus — ни replay, ни обращений к
файловой системе.

`FilesystemCorpus` — встроенная реализация: один небольшой JSON-файл на
property (`<sha1(id)>.json`, максимум 8 values-записей и 2 seed-записи,
старейшие вытесняются; атомарная, сериализованная блокировкой запись). Формат
байт-совместим с корпусом, записанным `rasuvaeff/property-testing` 2.8 —
существующие корпуса продолжают работать после миграции.

| Запись (`CorpusEntry`) | Когда | Replay |
|---|---|---|
| Values | Каждый минимизированный аргумент представим как данные (null/скаляры/массивы/enum-case'ы/байтовые строки) | Один прогон с точным записанным входом |
| Seed | Объекты, замыкания или значения `Gen::draw()` в контрпримере | Вся случайная фаза с этим seed; отгораживается sequence epoch |

Корпус — единственная память property между прогонами, а большинство
фальсификаций случается в CI, на машине, которая исчезает вместе с job'ом.
Перенос корпуса между прогонами — три шага, и каждый существует из-за
конкретного тихого отказа (объединённый cache-action не сохраняет на красном
job'е — ровно тогда, когда корпус и был записан); рецепт и ловушки —
[The corpus as a CI artifact](https://rasuvaeff.github.io/property-testing-core/guide/regression-corpus#the-corpus-as-a-ci-artifact).

`RedisCorpus` — общий вариант: тот же документ, но в Redis вместо каталога, так
что падение, найденное на ноутбуке, воспроизводится в CI, а найденное в CI — на
следующем ноутбуке. Он принимает небольшой шов клиента (`CorpusClient`, с
`PhpRedisCorpusClient` для `ext-redis` и `PredisCorpusClient` для predis) и
пишет оптимистично (чтение, compare-and-set, повтор), а не под блокировкой,
после нескольких попыток тихо сдаваясь: корпус — это память, а не журнал, и
ронять прошедший прогон ради записи контрпримера — неверный размен.

```php
$corpus = new RedisCorpus(new PhpRedisCorpusClient($redis));
```

Общий корпус — это общее пространство значений: values-запись есть вывод
генератора, и её прочтёт всякий, кто имеет доступ к этому Redis.

Values-запись хранит падающий вход **дословно**, поэтому каталог корпуса
настолько же чувствителен, насколько чувствительны данные, которые производят
ваши генераторы. Обычно это неинтересно — случайные числа и строки, — но
генератор, засеянный продакшн-фикстурой, или собирающий правдоподобные
персональные либо похожие на учётные данные, запишет ровно их на диск в JSON
открытым текстом. Держите каталог вне общедоступных путей и вне публикуемых
артефактов сборки, а такие значения лучше синтезировать внутри тела property,
а не генерировать: тогда в контрпример попадёт seed, а не сами данные.

### Id property и замыкания (`PropertyId`)

Id property ключует и события, которые агрегирует listener, и запись корпуса,
воспроизводящую вчерашний контрпример, — значит завтра он должен называть ту же
property. Адаптер, выводящий его из бэктрейса, получает это для метода теста и
теряет для замыкания: стабильного имени у замыкания в PHP никогда не было.

```text
PHP 8.3   Suite::{closure}
PHP 8.4+  Suite::{closure:/app/tests/StackTest.php:19}
```

На 8.3 все замыкания класса схлопываются в один id, и две property одного файла
затирают контрпримеры друг друга; с 8.4 id содержит номер строки — вставка
строки выше осиротит вчерашнюю запись. Ни там, ни там ничего не бросается:
корпус просто перестаёт воспроизводить падение, ради которого он и существует.

`PropertyId::unstableWarning($id)` возвращает фразу, которую следует показать
для такого id, или `null`, если говорить не о чем. Это диагноз, а не лечение:
движок возвращает текст, печатает адаптер — сам движок никуда не пишет.
Лечение — адаптеры, позволяющие назвать property явно (`forAll($generators,
$id)` в PHPUnit-адаптере): переданный id берётся как есть.

### События и listeners

Передайте реализации `PropertyListener` в `PropertyRunner::run()` и наблюдайте
весь жизненный цикл — так подключаются console reporter, экспортёр телеметрии
или IDE-интеграция без каких-либо изменений движка:

| Событие | Когда |
|---|---|
| `PropertyStarted` / `PropertyFinished` | Вокруг всей property (id, seed, runs / финальный failure или null) |
| `ExampleStarted` / `ExampleFinished` | Вокруг каждого явного example |
| `RunStarted` / `RunPassed` / `RunDiscarded` / `RunFailed` | Вокруг каждого случайного прогона (аргументы, draws, метки, время) |
| `ShrinkTried` / `ShrinkAccepted` | На каждый shrink-кандидат / принятый шаг |
| `CorpusReplayed` / `CorpusPruned` / `CorpusStored` | Активность корпуса |

События несут только данные движка — никогда типы фреймворков. Исключение
listener'а прерывает прогон (падение наблюдателя — инфраструктурная авария, а
не то, что нужно прятать), и listener никогда не меняет исход property. См.
[`examples/custom_listeners.php`](examples/custom_listeners.php) — console
reporter и telemetry-коллектор, построенные чисто на событиях.

### Детерминированное время (`Clock`)

Runner меряет дедлайны и бюджеты через абстракцию `Clock` — `MonotonicClock`
(default, на `hrtime`) в бою, fake clock в тестах, инъекция через конструктор
`PropertyRunner`. Именно это делает поведение `timeoutMs`/`budgetMs` точно
тестируемым.

### Собственный arbitrary

Любое пространство значений достижимо прямой реализацией
`ArbitraryInterface`: `generate(Random)` возвращает `Shrinkable` — значение
плюс ленивое дерево меньших кандидатов, самые агрессивные первыми, каждый со
своим поддеревом. Случайность — только через инъецированный `Random`
(`int()`, `float()`, `bytes()`), чтобы seed-прогоны оставались
воспроизводимыми. `Shrinkable::leaf($value)` строит терминальный узел;
`Shrinkable::of($value, $closure)` подвешивает лениво вычисляемых кандидатов;
`Shrinkable::map($fn)` преобразует всё дерево. Держите каждую ветвь конечной
и никогда не отдавайте кандидата, равного родителю — это и гарантирует
завершение shrinking.

### Stateful / model-based тестирование

Часть багов проявляется только на *последовательности* операций. Реализуйте
`Command` (`preCondition` / `nextState` / `run` / `postCondition` плюс
`__toString`-метка), генерируйте валидные последовательности через
`Gen::commands()` и прогоняйте их `StateMachine::check()` внутри тела
property — упавший postcondition бросает `PostconditionViolation` с номером
шага, а падающая `CommandSequence` сжимается до кратчайшей ломающей
последовательности:

```php
$definition = new PropertyDefinition(
    id: 'demo::stackBehavesLikeItsModel',
    name: 'stackBehavesLikeItsModel',
    generators: ['sequence' => Gen::commands([], [
        Gen::map(Gen::intBetween(0, 99), static fn(int $v): Command => new Push($v)),
        Gen::constant(new Pop()),
    ])],
    parameterNames: ['sequence'],
    config: new PropertyConfig(runs: 200),
);

$result = (new PropertyRunner())->run($definition, new CallableTrialExecutor(
    static function (CommandSequence $sequence): void {
        StateMachine::check($sequence, static fn(): Stack => new Stack());
    },
));
```

### Экспорт контрпримера

`CounterExample` открывает `seed`, `runsBeforeFailure`, `originalArguments`,
`shrunkArguments`, `shrinkSteps`, `shrinkTrials`, `skips` и исходный
`failure`; `toArray()`/`toJson()` возвращают нормализованную машинную форму, а
`toExamplesCode()` печатает исполняемый PHP, пиняющий сжатый случай как
постоянный example. Контрпример, который невозможно воспроизвести example'ом —
с неэкспортируемым объектом или значениями in-body `Gen::draw()` — бросает
`LogicException` вместо генерации сломанного кода; такие случаи реплеятся
через seed.

`ValueRenderer::render($value)` даёт однострочную человеческую форму значения —
ту же, что и в сообщении о контрпримере (строки в кавычках и с экранированием,
массивы и объекты сворачиваются, рекурсия и глубина ограничены). Адаптеры
переиспользуют его, чтобы verbose-вывод читался так же, как сообщение о провале.

### Отладка генераторов

`Gen::sample($arb, $count, $seed)` жадно генерирует значения;
`Gen::sampleShrinks($arb, $seed)` показывает одно значение плюс его первые
shrink-кандидаты — быстрейший способ проверить, что кастомный arbitrary
сжимается как задумано.

## Безопасность

Движок сам не делает I/O, SQL, shell и сетевых операций; единственный доступ к
файловой системе — опциональный `FilesystemCorpus`, и только когда вы сами
передаёте его runner'у. Случайные значения — из движка MT19937, посеянного
seed'ом из отчёта: это PRNG, не CSPRNG; никогда не используйте
сгенерированные значения для криптографии, а seed трактуйте как ручку
воспроизводимости, не как секрет.

Если корпус всё же включён — помните, что он сохраняет падающие входы в JSON
открытым текстом: что это значит для генераторов, способных произвести
чувствительные данные, — в разделе
[Регрессионный корпус](#регрессионный-корпус).

## Примеры

Исполняемые скрипты — в [examples/](examples/).

| Скрипт | Показывает | Нужен сервер? |
|---|---|---|
| `basic.php` | property, которая выполняется; property, которая фальсифицируется; shrinking по дереву | Нет |
| `generators.php` | `sample`, boundary bias, `uuid`, `datetime`, `dictOf`, `record`, `flatMap` | Нет |
| `standalone_runner.php` | прямое управление движком: `PropertyDefinition`, `CallableTrialExecutor`, структурный `PropertyResult` | Нет |
| `custom_listeners.php` | console reporter и telemetry-коллектор как чистые `PropertyListener` | Нет |

## Разработка

PHP/Composer на хосте нет. Команды выполняются в Docker через образ `composer:2`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Или через Make:

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` и `make mutation` поднимают `pcov` внутри контейнера
`composer:2`, потому что в базовом образе нет coverage-драйвера.

## Лицензия

[BSD-3-Clause](LICENSE.md)

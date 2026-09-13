<?php

/**
 * The page itself. Kept apart from index.php so the markup reads as markup.
 *
 * @var list<ToolRun> $runs
 */

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Architecture checks</title>
    <style>
        :root {
            --ground: #f6f7f9;
            --card: #fff;
            --ink: #171a1f;
            --dim: #5d6675;
            --rule: #dcdfe6;
            --ok: #2f7d72;
            --bad: #a8372c;
            --accent: #2f5d8a;
            --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            --sans: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --ground: #101317;
                --card: #171b21;
                --ink: #e6e9ee;
                --dim: #9aa3b2;
                --rule: #2a303a;
                --ok: #5fb3a4;
                --bad: #e0796b;
                --accent: #7aa8d4;
            }
        }

        * {
            box-sizing: border-box
        }

        body {
            background: var(--ground);
            color: var(--ink);
            font-family: var(--sans);
            line-height: 1.55;
            margin: 0
        }

        .wrap {
            max-width: 1040px;
            margin: 0 auto;
            padding: 44px 24px 80px
        }

        h1 {
            font-size: 1.85rem;
            font-weight: 600;
            letter-spacing: -.02em;
            margin: 0 0 4px
        }

        .sub {
            color: var(--dim);
            margin: 0 0 32px
        }

        .sub code {
            background: var(--card);
            border: 1px solid var(--rule);
            border-radius: 2px;
            padding: 1px 5px
        }

        article {
            background: var(--card);
            border: 1px solid var(--rule);
            border-radius: 3px;
            padding: 20px 22px;
            margin-bottom: 18px
        }

        .head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap
        }

        h2 {
            font-size: 1.18rem;
            font-weight: 600;
            margin: 0
        }

        .ran {
            color: var(--dim);
            font-size: .8rem;
            font-family: var(--mono)
        }

        .about {
            color: var(--dim);
            margin: 4px 0 16px;
            font-size: .92rem;
            max-width: 70ch
        }

        .verdict {
            display: inline-flex;
            align-items: baseline;
            gap: 8px;
            border: 1px solid var(--rule);
            border-radius: 3px;
            padding: 8px 14px;
            margin-bottom: 14px
        }

        .verdict b {
            font-family: var(--mono);
            font-size: 1.45rem;
            font-weight: 500;
            font-variant-numeric: tabular-nums
        }

        .verdict.clean {
            border-color: var(--ok)
        }

        .verdict.clean b {
            color: var(--ok)
        }

        .verdict.broken {
            border-color: var(--bad)
        }

        .verdict.broken b {
            color: var(--bad)
        }

        .verdict span {
            color: var(--dim);
            font-size: .85rem
        }

        .files {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            font-size: .87rem;
            margin-bottom: 16px
        }

        .files a {
            color: var(--accent)
        }

        figure {
            margin: 0 0 16px;
            border: 1px solid var(--rule);
            border-radius: 3px;
            overflow: auto;
        }

        figure img {
            display: block;
            max-width: none;
            width: 100%;
        }

        pre {
            background: var(--ground);
            border: 1px solid var(--rule);
            border-radius: 2px;
            padding: 13px 15px;
            overflow: auto;
            font-family: var(--mono);
            font-size: .78rem;
            line-height: 1.45;
            margin: 0;
            max-height: 440px
        }

        .empty {
            color: var(--dim)
        }

        footer {
            color: var(--dim);
            font-size: .82rem;
            margin-top: 28px;
            padding-top: 16px;
            border-top: 1px solid var(--rule)
        }
    </style>
</head>
<body>
<div class="wrap">

    <h1>Architecture checks</h1>
    <p class="sub">The last run of each tool over <code>src/</code>. Run them again with
        <code>ddev opendxp-tests-run analysis</code>.</p>

    <?php if ($runs === []) { ?>
        <article><p class="empty">Nothing has run yet.</p></article>
    <?php } ?>

    <?php foreach ($runs as $run) { ?>
        <article>
            <div class="head">
                <h2><?= e($run->tool) ?></h2>
                <span class="ran"><?= e(date('Y-m-d H:i', $run->ranAt)) ?></span>
            </div>
            <p class="about"><?= e($run->about) ?></p>

            <?php if ($run->violations !== null) { ?>
                <div class="verdict <?= $run->violations === 0 ? 'clean' : 'broken' ?>">
                    <b><?= $run->violations ?></b><span><?= $run->violations === 1 ? 'violation' : 'violations' ?></span>
                </div>
            <?php } ?>

            <?php if ($run->artefacts !== []) { ?>
                <div class="files">
                    <?php foreach ($run->artefacts as $label => $file) { ?>
                        <a href="<?= e($file) ?>"><?= e($label) ?></a>
                    <?php } ?>
                </div>
            <?php } ?>

            <?php foreach ($run->artefacts as $file) {
                if (str_ends_with($file, '.png')) { ?>
                    <figure><a href="<?= e($file) ?>"><img src="<?= e($file) ?>" alt="dependency graph"></a></figure>
                <?php }
            } ?>

            <pre><?= e(tail($run->output, 120)) ?></pre>
        </article>
    <?php } ?>

    <footer>Written <?= e(date('Y-m-d H:i')) ?>.
    </footer>

</div>
</body>
</html>

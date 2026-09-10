# The report page

`ddev opendxp-analysis` deposits its output under `app/var/analysis/` and prints the URL. The
directory is symlinked into `app/public/`, so the web server that is already running serves it and
the last measurement stays readable without running anything.

```
index.php            builds index.html from what the tools wrote
index.template.php   the markup, kept apart from the reading
layer-graph.php      draws deptrac's layer graph as graphviz source
```

`index.php` reads deptrac's numbers out of its JSON report and PHPArkitect's out of its one summary
line, so the report never states a number the tools did not measure.

## The picture

`layer-graph.php` writes graphviz source on stdout and the command pipes it through `dot`. It draws
only the violations. The allowed graph carries no information: a layered architecture says every
layer may use everything below it, so drawing it produces a filled triangle. What is worth looking
at is the edges that should not be there, and how heavy each one is.

The tiers come out of the ruleset rather than being written down again, so the picture cannot drift
from the rules. Two layers that may each use the other would have no tier at all, which is a finding
of its own: they are one layer.

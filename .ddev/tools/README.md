# The report page

`ddev opendxp-analysis` deposits its output under `app/var/analysis/` and prints the URL. The
directory is symlinked into `app/public/`, so the web server that is already running serves it and
the last measurement stays readable without running anything.

```
index.php            builds index.html from what the tools wrote
index.template.php   the markup, kept apart from the reading
```

`index.php` reads deptrac's numbers out of its JSON report and PHPArkitect's out of its one summary
line, so the report never states a number the tools did not measure.

## The picture

`deptrac.png` is deptrac's own graphviz output. It draws one node per layer, labels each edge with
how many dependencies it stands for, and colours a violated edge red. Its configuration lives with
the rules it draws, in the bundle's `deptrac.yaml`.

At this size the graph is dense, and that is the honest state of it: a layered architecture allows
most of its own edges, so a picture of all of them is busy by construction. The precise pairs behind
each red edge are in `deptrac.txt` and `deptrac.json` next to it.

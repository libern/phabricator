<?php

/**
 * @group search
 */
final class PhabricatorSearchManagementIndexWorkflow
  extends PhabricatorSearchManagementWorkflow {

  protected function didConstruct() {
    $this
      ->setName('index')
      ->setSynopsis('Build or rebuild search indexes.')
      ->setExamples(
        "**index** D123\n".
        "**index** --type DREV\n".
        "**index** --all")
      ->setArguments(
        array(
          array(
            'name' => 'all',
            'help' => 'Reindex all documents.',
          ),
          array(
            'name'  => 'type',
            'param' => 'TYPE',
            'help'  => 'PHID type to reindex, like "TASK" or "DREV".',
          ),
          array(
            'name' => 'background',
            'help' => 'Instead of indexing in this process, queue tasks for '.
                      'the daemons. This can improve performance, but makes '.
                      'it more difficult to debug search indexing.',
          ),
          array(
            'name'      => 'objects',
            'wildcard'  => true,
          ),
        ));
  }

  public function execute(PhutilArgumentParser $args) {
    $console = PhutilConsole::getConsole();

    $is_all = $args->getArg('all');
    $is_type = $args->getArg('type');

    $obj_names = $args->getArg('objects');

    if ($obj_names && ($is_all || $is_type)) {
      throw new PhutilArgumentUsageException(
        "You can not name objects to index alongside the '--all' or '--type' ".
        "flags.");
    } else if (!$obj_names && !($is_all || $is_type)) {
      throw new PhutilArgumentUsageException(
        "Provide one of '--all', '--type' or a list of object names.");
    }

    if ($obj_names) {
      $phids = $this->loadPHIDsByNames($obj_names);
    } else {
      $phids = $this->loadPHIDsByTypes($is_type);
    }

    if (!$phids) {
      throw new PhutilArgumentUsageException(
        "Nothing to index!");
    }

    if ($args->getArg('background')) {
      $is_background = true;
    } else {
      PhabricatorWorker::setRunAllTasksInProcess(true);
      $is_background = false;
    }

    $groups = phid_group_by_type($phids);
    foreach ($groups as $group_type => $group) {
      $console->writeOut(
        "%s\n",
        pht(
          "Indexing %d object(s) of type %s.",
          count($group),
          $group_type));
    }

    $indexer = new PhabricatorSearchIndexer();
    foreach ($phids as $phid) {
      if ($is_background) {
        $console->writeOut("%s\n", pht("Queueing '%s'...", $phid));
      } else {
        $console->writeOut("%s\n", pht("Indexing '%s'...", $phid));
      }
      $indexer->queueDocumentForIndexing($phid);
    }

    $console->writeOut("Done.\n");
  }

  private function loadPHIDsByNames(array $names) {
    $query = id(new PhabricatorObjectQuery())
      ->setViewer($this->getViewer())
      ->withNames($names);
    $query->execute();
    $objects = $query->getNamedResults();

    foreach ($names as $name) {
      if (empty($objects[$name])) {
        throw new PhutilArgumentUsageException(
          "'{$name}' is not the name of a known object.");
      }
    }

    return mpull($objects, 'getPHID');
  }

  private function loadPHIDsByTypes($type) {
    $indexer_symbols = id(new PhutilSymbolLoader())
      ->setAncestorClass('PhabricatorSearchDocumentIndexer')
      ->setConcreteOnly(true)
      ->setType('class')
      ->selectAndLoadSymbols();

    $indexers = array();
    foreach ($indexer_symbols as $symbol) {
      $indexers[] = newv($symbol['name'], array());
    }

    $phids = array();
    foreach ($indexers as $indexer) {
      $indexer_phid = $indexer->getIndexableObject()->generatePHID();
      $indexer_type = phid_get_type($indexer_phid);

      if ($type && strcasecmp($indexer_type, $type)) {
        continue;
      }

      $iterator = $indexer->getIndexIterator();
      foreach ($iterator as $object) {
        $phids[] = $object->getPHID();
      }
    }

    return $phids;
  }

}

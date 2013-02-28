<?
defined('C5_EXECUTE') or die("Access Denied.");
class Concrete5_Controller_Block_CoreAggregator extends BlockController {

		protected $btCacheBlockRecord = true;
		protected $btTable = 'btCoreAggregator';

		public function getBlockTypeDescription() {
			return t("Displays pages and data in list or grids.");
		}
		
		public function getBlockTypeName() {
			return t("Aggregator");
		}

		public function duplicate($newBID) {
			$ar = Aggregator::getByID($this->agID);
			$nr = $ar->duplicate();
			$this->record->agID = $nr->getAggregatorID();
			parent::duplicate($newBID);
		}

		protected function setupForm() {
			$aggregator = false;
			$activeSources = array();
			if ($this->agID) {
				$aggregator = Aggregator::getByID($this->agID);
				$configuredSources = $aggregator->getConfiguredAggregatorDataSources();
				foreach($configuredSources as $source) {
					$activeSources[$source->getAggregatorDataSourceID()] = $source;
				}
			}
			$availableSources = AggregatorDataSource::getList();
			$this->set('availableSources', $availableSources);
			$this->set('activeSources', $activeSources);
			$this->set('aggregator', $aggregator);
		}

		public function add() {
			$this->setupForm();
		}

		public function edit() {
			$this->setupForm();
		}

		public function save() {
			$db = Loader::db();
			$agID = $db->GetOne('select agID from btCoreAggregator where bID = ?', array($this->bID));
			if (!$agID) {
				$ag = Aggregator::add();
			} else {
				$ag = Aggregator::getByID($agID);
			}

			$ag->clearConfiguredAggregatorDataSources();
			if (is_array($this->post('source'))) {
				foreach($this->post('source') as $agsID) {
					$ags = AggregatorDataSource::getByID($agsID);
					$agc = $ags->configure($ag, $this->post());	
				}
			}
			$ag->generateAggregatorItems();
			$values = array('agID' => $ag->getAggregatorID());
			parent::save($values);
		}

		public function on_page_view() {
			if ($this->agID) {
				$aggregator = Aggregator::getByID($this->agID);
				if (is_object($aggregator)) {
					$this->addHeaderItem(Loader::helper('html')->css('ccm.aggregator.css'));
					$this->addFooterItem(Loader::helper('html')->javascript('jquery.gridster.js'));
				}
			}
		}

		public function delete() {
			parent::delete();
			if ($this->agID) {
				$aggregator = Aggregator::getByID($this->agID);
				if (is_object($aggregator)) {
					$aggregator->delete();
				}
			}
		}

		public function view() {
			if ($this->agID) {
				$aggregator = Aggregator::getByID($this->agID);
				if (is_object($aggregator)) {
					$list = new AggregatorItemList($aggregator);
					$list->sortByDateDescending();
					$items = $list->getPage();
					$this->set('aggregator', $aggregator);
					$this->set('itemList', $list);
					$this->set('items', $items);
				}
			}
		}

	}


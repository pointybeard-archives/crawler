<?php

	Class extension_Crawler extends Extension{

/*
		public function getSubscribedDelegates(){
			return array(
				array(
					'page' => '/system/preferences/',
					'delegate' => 'AddCustomPreferenceFieldsets',
					'callback' => 'appendPreferences'
				),
				array(
					'page' => '/system/preferences/',
					'delegate' => 'Save',
					'callback' => '__SavePreferences'
				)
			);
		}
*/
		public function install() {
			try {

			}
			catch (Exception $ex) {
				$extension = $this->about();
				Administration::instance()->Page->pageAlert(__('An error occurred while installing %s. %s', array($extension['name'], $ex->getMessage())), Alert::ERROR);
				return false;
			}
		}

		public function enable(){
			return $this->install();
		}

		public function update($previousVersion = false){
		}

		public function uninstall(){

			return $this->disable();
		}

		public function disable() {
			try {

			}
			catch (Exception $ex) {
				$extension = $this->about();
				Administration::instance()->Page->pageAlert(__('An error occurred while installing %s. %s', array($extension['name'], $ex->getMessage())), Alert::ERROR);
				return false;
			}
		}

	}

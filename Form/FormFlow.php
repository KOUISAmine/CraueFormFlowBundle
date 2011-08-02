<?php

namespace Craue\FormFlowBundle\Form;

use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session;

/**
 * @author Christian Raue <christian.raue@gmail.com>
 * @copyright 2011 Christian Raue
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
class FormFlow {

	const TRANSITION_BACK = 'back';
	const TRANSITION_RESET = 'reset';

	protected $formType;
	protected $formFactory;
	protected $request;
	protected $session;

	protected $id;
	protected $formStepKey;
	protected $formTransitionKey;
	protected $sessionDataKey;
	protected $validationGroupPrefix;
	protected $maxSteps;
	protected $currentStep;
	protected $transition;

	public function setFormFactory(FormFactoryInterface $formFactory) {
		$this->formFactory = $formFactory;
	}

	public function setRequest(Request $request) {
		$this->request = $request;
	}

	public function setSession(Session $session) {
		$this->session = $session;
	}

	public function setFormType(FormTypeInterface $formType) {
		$this->formType = $formType;
		if (empty($this->id)) {
			$this->id = 'flow_' . $this->formType->getName();
		}
		if (empty($this->validationGroupPrefix)) {
			$this->validationGroupPrefix = $this->id. '_step';
		}
		if (empty($this->formStepKey)) {
			$this->formStepKey = $this->id. '_step';
		}
		if (empty($this->formTransitionKey)) {
			$this->formTransitionKey = $this->id. '_transition';
		}
		if (empty($this->sessionDataKey)) {
			$this->sessionDataKey = $this->id. '_data';
		}
	}

	public function setId($id) {
		$this->id = $id;
	}

	public function getId() {
		return $this->id;
	}

	public function setFormStepKey($formStepKey) {
		$this->formStepKey = $formStepKey;
	}

	public function getFormStepKey() {
		return $this->formStepKey;
	}

	public function setFormTransitionKey($formTransitionKey) {
		$this->formTransitionKey = $formTransitionKey;
	}

	public function getFormTransitionKey() {
		return $this->formTransitionKey;
	}

	public function setSessionDataKey($sessionDataKey) {
		$this->sessionDataKey = $sessionDataKey;
	}

	public function getSessionDataKey() {
		return $this->sessionDataKey;
	}

	public function setValidationGroupPrefix($validationGroupPrefix) {
		$this->validationGroupPrefix = $validationGroupPrefix;
	}

	public function getValidationGroupPrefix() {
		return $this->validationGroupPrefix;
	}

	public function getFormType() {
		return $this->formType;
	}

	public function setMaxSteps($maxSteps) {
		$this->maxSteps = $maxSteps;
	}

	public function getMaxSteps() {
		return $this->maxSteps;
	}

	public function setCurrentStep($currentStep) {
		$this->currentStep = $currentStep;
	}

	public function getCurrentStep() {
		return $this->currentStep;
	}

	public function reset() {
		$this->session->set($this->sessionDataKey, array());
		$this->currentStep = 1;
	}

	public function nextStep() {
		++$this->currentStep;
	}

	public function isStepDone($step) {
		$sessionData = $this->session->get($this->sessionDataKey);
		return array_key_exists($step, $sessionData);
	}

	public function getRequestedTransition() {
		if (empty($this->transition)) {
			$this->transition = $this->request->request->get($this->formTransitionKey);
		}

		return $this->transition;
	}

	public function bind($formData) {
		if ($this->request->getMethod() === 'POST') {
			$requestedTransition = $this->getRequestedTransition();
			if ($requestedTransition === self::TRANSITION_RESET) {
				$this->reset();
			} else {
				$requestedStep = $this->request->request->get($this->formStepKey, 1);
				if ($requestedTransition === self::TRANSITION_BACK) {
					--$requestedStep;
				}

				// ensure that 1 <= $requestedStep <= $this->maxSteps
				$requestedStep = min(max(1, $requestedStep), $this->maxSteps);

				// ensure that requested step fits the current progress
				if ($requestedStep > 1 && !$this->isStepDone($requestedStep - 1)) {
					$this->reset();
					$this->transition = self::TRANSITION_RESET;
				} else {
					$this->currentStep = $requestedStep;
					$this->applyDataFromPreviousSteps($formData);
					if ($requestedTransition === self::TRANSITION_BACK) {
						$this->invalidateStepData($this->currentStep);
					}
				}
			}
		} else {
			$this->reset();
		}
	}

	public function saveCurrentStepData() {
		$sessionData = $this->session->get($this->sessionDataKey);

		$sessionData[$this->currentStep] = array_replace_recursive(
			$this->request->request->get($this->formType->getName(), array()),
			$this->request->files->get($this->formType->getName(), array())
		);

		$this->session->set($this->sessionDataKey, $sessionData);
	}

	/**
	 * Invalidates data for steps >= $fromStep.
	 * @param int $fromStep
	 */
	public function invalidateStepData($fromStep) {
		$sessionData = $this->session->get($this->sessionDataKey);

		for ($step = $fromStep; $step < $this->maxSteps; ++$step) {
			unset($sessionData[$step]);
		}

		$this->session->set($this->sessionDataKey, $sessionData);
	}

	/**
	 * Updates form data class with form data from previous steps.
	 * @param mixed $formData
	 * @param array $formOptions
	 */
	public function applyDataFromPreviousSteps($formData, array $formOptions = array()) {
		$sessionData = $this->session->get($this->sessionDataKey);

		/*
		 * Last iteration ($step === $this->currentStep) is only needed to fill out the form when using the "back"
		 * button.
		 */
		for ($step = 1; $step <= $this->currentStep; ++$step) {
			if (array_key_exists($step, $sessionData)) {
				$options = $this->getFormOptions($formData, $step, $formOptions);
				$stepForm = $this->formFactory->create($this->formType, $formData, $options);
				$stepForm->bind($sessionData[$step]);
			}
		}
	}

	public function createForm($formData, array $options = array()) {
		return $this->formFactory->create($this->formType, $formData,
				$this->getFormOptions($formData, $this->currentStep, $options));
	}

	public function getFormOptions($formData, $step, array $options = array()) {
		$options['flowStep'] = $step;
		$options['validation_groups'] = $this->validationGroupPrefix . $step;

		return $options;
	}

}

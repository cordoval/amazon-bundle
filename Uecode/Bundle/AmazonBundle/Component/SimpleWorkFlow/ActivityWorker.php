<?php

/**
 * Activity worker
 *
 * @package amazon-bundle
 * @copyright (c) 2013 Underground Elephant
 * @author John Pancoast
 *
 * Copyright 2013 Underground Elephant
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Uecode\Bundle\AmazonBundle\Component\SimpleWorkFlow;

// Amazon Components
use \Uecode\Bundle\AmazonBundle\Model\SimpleWorkFlow;
use \Uecode\Bundle\AmazonBundle\Component\SimpleWorkFlow\Worker;

// Amazon Exceptions
use \Uecode\Bundle\AmazonBundle\Exception\InvalidClassException;

// Amazon Classes
use \AmazonSWF;
use \CFResponse as CFResponse;

class ActivityWorker extends Worker
{
	/**
	 * @var string The task list this activity worker polls amazon for.
	 *
	 * @access protected
	 * @see http://docs.aws.amazon.com/amazonswf/latest/apireference/API_PollForActivityTask.html
	 */
	protected $taskList;

	/**
	 * @var string A user-defined identity for this activity worker.
	 *
	 * @access protected
	 * @see http://docs.aws.amazon.com/amazonswf/latest/apireference/API_PollForActivityTask.html
	 */
	protected $identity;

	/**
	 * constructor
	 *
	 * @access protected
	 * @param AmazonSWF $swf Simple workflow object
	 * @param string The SWF domain this worker is working in
	 * @param string $taskList
	 * @param string $activityVersion
	 * @param string $identity
	 */
	public function __construct(AmazonSWF $swf, $domain, $taskList, $activityVersion, $identity = null)
	{
		parent::__construct($swf);

		$this->domain = $domain;
		$this->taskList = $taskList;
		$this->activityVersion = $activityVersion;
		$this->identity = $identity;
	}

	/**
	 * Run the activity worker.
	 *
	 * This will make a request to amazon (a long poll) waiting for an activity task
	 * to perform. If amazon doesn't respond within a minute, they'll send an empty
	 * response and we'll start another loop. If they respond with an activity task
	 * we'll further process that {@see self::runActivity()}.
	 *
	 * @access public
	 * @final
	 * @uses self::runActivity()
	 */
	final public function run()
	{
		$this->log(
			'info',
			'Starting activity worker polling'
		);

		try {
			// run until we receive a signal to stop
			while ($this->doRun()) {
				$this->response = null;

				// these values can only be set from amazon response
				$this->setAmazonRunId(null);
				$this->setAmazonWorkflowId(null);

				$pollRequest = array(
					'taskList' => array(
						'name' => $this->taskList,
					),
					'domain' => $this->domain,
					'identity' => $this->identity
				);

				$this->response = $this->amazonClass->poll_for_activity_task($pollRequest);

				$this->log(
					'debug',
					'PollForActivityTask',
					array(
						'request' => $pollRequest,
						'response' => json_decode(json_encode($this->response), true)
					)
				);

				if ($this->response->isOK()) {
					$taskToken = (string)$this->response->body->taskToken;

					if (!empty($taskToken)) {
						// set relevant amazon ids
						$this->setAmazonRunId((string)$this->response->body->workflowExecution->runId);
						$this->setAmazonWorkflowId((string)$this->response->body->workflowExecution->workflowId);

						$this->log(
							'info',
							'PollForActivityTask activity task received',
							array(
								'taskToken' => $taskToken
							)
						);

						$this->runActivity($this->response);
					} else {
						$this->log(
							'debug',
							'PollForActivityTask received empty response'
						);
					}
				} else {
					$this->log(
						'critical',
						'PollForActivityTask failed'
					);
				}
			}
		} catch (Exception $e) {
			$this->log(
				'critical',
				'Exception in activity worker: '.get_class($e).' - '.$e->getMessage(),
				array(
					'trace' => $e->getTrace()
				)
			);
		}
	}

	/**
	 * Given an activity worker response, run the activity.
	 *
	 * This will search for an activity class that matches the name in the response.
	 * It will search in the directory you specify in the uecode.amazon.simpleworkflow.domains.[domain].activities.directory
	 * config value. Activity classes must extend AbstractActivity.
	 *
	 * @access protected
	 */
	public function runActivity()
	{
		try {
			$name = $this->response->body->activityType->name;
			$version = $this->response->body->activityType->version;
			$token = (string)$this->response->body->taskToken;
			$class = $this->getActivityClass($name, $version);

			if (class_exists($class)) {
				$this->log(
					'info',
					'Activity task class found',
					array(
						'class' => $class
					)
				);

				$obj = new $class;

				if (!($obj instanceof AbstractActivity)) {
					throw new InvalidClassException('Activity class "'.$class.'" must extend AbstractActivity.');
				}

				$request = $obj->run($token, $this);
				$request->taskToken = $request->taskToken ?: $token;

				$method = 'respond_activity_task_'.str_replace('ActivityTask', '', basename(str_replace('\\', '/', get_class($request))));

				$this->response = $this->amazonClass->{$method}((array)$request);

				if ($this->response->isOK()) {
					$this->log(
						'info',
						'Activity completed (RespondActivityTaskCompleted successful)',
						array(
							'request' => (array)$request,
							'response' => (array)$this->response
						)
					);
				} else {
					$this->log(
						'critical',
						'Activity failed (RespondActivityTaskCompleted failed)',
						array(
							'request' => $request,
							'response' => $this->response
						)
					);
				}
			} else {
				$this->log(
					'warning',
					'Activity task class not found',
					array(
						'class' => $class
					)
				);
			}
		} catch (\Exception $e) {
			$this->log(
				'critical',
				'Exception in activity worker: '.get_class($e).' - '.$e->getMessage(),
				array(
					'trace' => $e->getTrace()
				)
			);
		}
	}
}

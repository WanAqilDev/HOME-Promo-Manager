<?php

namespace HomePromoManager;

use DateTime;
use Exception;

/**
 * Validator Class
 * 
 * Handles validation logic for HOME Promo Manager system
 * Includes eligibility checks for 4 categories: new, passive, diagnostic, lead
 * Implements error handling and comprehensive logging
 * 
 * @package HomePromoManager
 * @version 1.0.0
 * @date 2026-01-03
 * @specification Smart 26
 */
class Validator
{
    /**
     * @var Logger Logger instance for validation tracking
     */
    private $logger;

    /**
     * @var array Validation rules configuration
     */
    private $validationRules;

    /**
     * @var array Eligibility criteria for each category
     */
    private $eligibilityCriteria;

    /**
     * @var array Validation errors
     */
    private $errors = [];

    /**
     * Category constants
     */
    const CATEGORY_NEW = 'new';
    const CATEGORY_PASSIVE = 'passive';
    const CATEGORY_DIAGNOSTIC = 'diagnostic';
    const CATEGORY_LEAD = 'lead';

    /**
     * Status constants
     */
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_SUSPENDED = 'suspended';

    /**
     * Constructor
     * 
     * @param Logger|null $logger Optional logger instance
     */
    public function __construct($logger = null)
    {
        $this->logger = $logger ?? new Logger();
        $this->initializeValidationRules();
        $this->initializeEligibilityCriteria();
        
        $this->logger->info('Validator initialized', [
            'timestamp' => date('Y-m-d H:i:s'),
            'specification' => 'Smart 26'
        ]);
    }

    /**
     * Initialize validation rules
     */
    private function initializeValidationRules()
    {
        $this->validationRules = [
            'customer_id' => [
                'required' => true,
                'type' => 'string',
                'min_length' => 1,
                'max_length' => 50,
                'pattern' => '/^[A-Z0-9\-_]+$/i'
            ],
            'email' => [
                'required' => true,
                'type' => 'string',
                'format' => 'email',
                'max_length' => 255
            ],
            'phone' => [
                'required' => false,
                'type' => 'string',
                'pattern' => '/^[0-9\+\-\(\)\s]+$/',
                'min_length' => 10,
                'max_length' => 20
            ],
            'contract_date' => [
                'required' => true,
                'type' => 'date',
                'format' => 'Y-m-d'
            ],
            'last_activity_date' => [
                'required' => false,
                'type' => 'date',
                'format' => 'Y-m-d'
            ],
            'status' => [
                'required' => true,
                'type' => 'string',
                'enum' => [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_SUSPENDED]
            ],
            'category' => [
                'required' => true,
                'type' => 'string',
                'enum' => [self::CATEGORY_NEW, self::CATEGORY_PASSIVE, self::CATEGORY_DIAGNOSTIC, self::CATEGORY_LEAD]
            ],
            'total_purchases' => [
                'required' => false,
                'type' => 'numeric',
                'min' => 0
            ],
            'total_spent' => [
                'required' => false,
                'type' => 'numeric',
                'min' => 0
            ]
        ];
    }

    /**
     * Initialize eligibility criteria for each category
     */
    private function initializeEligibilityCriteria()
    {
        $this->eligibilityCriteria = [
            self::CATEGORY_NEW => [
                'description' => 'New customers with recent contracts',
                'criteria' => [
                    'contract_age_max_days' => 90,
                    'required_status' => [self::STATUS_ACTIVE],
                    'max_purchases' => 0,
                    'requires_verification' => true
                ]
            ],
            self::CATEGORY_PASSIVE => [
                'description' => 'Inactive customers requiring reactivation',
                'criteria' => [
                    'inactivity_min_days' => 180,
                    'inactivity_max_days' => 730,
                    'required_status' => [self::STATUS_INACTIVE],
                    'min_previous_purchases' => 1,
                    'requires_verification' => false
                ]
            ],
            self::CATEGORY_DIAGNOSTIC => [
                'description' => 'Customers requiring system diagnostics',
                'criteria' => [
                    'contract_age_min_days' => 30,
                    'required_status' => [self::STATUS_ACTIVE, self::STATUS_INACTIVE],
                    'requires_service_request' => true,
                    'requires_verification' => true
                ]
            ],
            self::CATEGORY_LEAD => [
                'description' => 'Potential customers and leads',
                'criteria' => [
                    'required_status' => [self::STATUS_ACTIVE],
                    'max_contract_age_days' => 30,
                    'requires_contact_info' => true,
                    'requires_verification' => false
                ]
            ]
        ];
    }

    /**
     * Validate customer data against defined rules
     * 
     * @param array $data Customer data to validate
     * @return bool True if validation passes
     */
    public function validate(array $data)
    {
        $this->errors = [];
        
        $this->logger->info('Starting validation', [
            'data_keys' => array_keys($data),
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        try {
            foreach ($this->validationRules as $field => $rules) {
                $this->validateField($field, $data[$field] ?? null, $rules);
            }

            $isValid = empty($this->errors);
            
            $this->logger->info('Validation completed', [
                'is_valid' => $isValid,
                'error_count' => count($this->errors),
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            return $isValid;
            
        } catch (Exception $e) {
            $this->handleValidationException($e, 'validate', $data);
            return false;
        }
    }

    /**
     * Validate a single field against its rules
     * 
     * @param string $field Field name
     * @param mixed $value Field value
     * @param array $rules Validation rules
     */
    private function validateField($field, $value, array $rules)
    {
        // Required validation
        if (isset($rules['required']) && $rules['required'] && empty($value) && $value !== '0' && $value !== 0) {
            $this->addError($field, "Field '{$field}' is required");
            return;
        }

        // Skip further validation if field is optional and empty
        if (empty($value) && (!isset($rules['required']) || !$rules['required'])) {
            return;
        }

        // Type validation
        if (isset($rules['type'])) {
            $this->validateType($field, $value, $rules['type']);
        }

        // Format validation
        if (isset($rules['format'])) {
            $this->validateFormat($field, $value, $rules['format']);
        }

        // Pattern validation
        if (isset($rules['pattern'])) {
            $this->validatePattern($field, $value, $rules['pattern']);
        }

        // Length validation
        if (isset($rules['min_length']) || isset($rules['max_length'])) {
            $this->validateLength($field, $value, $rules);
        }

        // Numeric range validation
        if (isset($rules['min']) || isset($rules['max'])) {
            $this->validateRange($field, $value, $rules);
        }

        // Enum validation
        if (isset($rules['enum'])) {
            $this->validateEnum($field, $value, $rules['enum']);
        }
    }

    /**
     * Validate field type
     * 
     * @param string $field Field name
     * @param mixed $value Field value
     * @param string $type Expected type
     */
    private function validateType($field, $value, $type)
    {
        $valid = false;

        switch ($type) {
            case 'string':
                $valid = is_string($value);
                break;
            case 'numeric':
                $valid = is_numeric($value);
                break;
            case 'integer':
                $valid = is_int($value) || (is_string($value) && ctype_digit($value));
                break;
            case 'float':
                $valid = is_float($value) || is_numeric($value);
                break;
            case 'boolean':
                $valid = is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true);
                break;
            case 'date':
                $valid = $this->isValidDate($value);
                break;
            case 'array':
                $valid = is_array($value);
                break;
        }

        if (!$valid) {
            $this->addError($field, "Field '{$field}' must be of type {$type}");
        }
    }

    /**
     * Validate field format
     * 
     * @param string $field Field name
     * @param mixed $value Field value
     * @param string $format Expected format
     */
    private function validateFormat($field, $value, $format)
    {
        $valid = false;

        switch ($format) {
            case 'email':
                $valid = filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
                break;
            case 'url':
                $valid = filter_var($value, FILTER_VALIDATE_URL) !== false;
                break;
            case 'ip':
                $valid = filter_var($value, FILTER_VALIDATE_IP) !== false;
                break;
        }

        if (!$valid) {
            $this->addError($field, "Field '{$field}' must be a valid {$format}");
        }
    }

    /**
     * Validate field pattern
     * 
     * @param string $field Field name
     * @param mixed $value Field value
     * @param string $pattern Regex pattern
     */
    private function validatePattern($field, $value, $pattern)
    {
        if (!preg_match($pattern, $value)) {
            $this->addError($field, "Field '{$field}' does not match required pattern");
        }
    }

    /**
     * Validate field length
     * 
     * @param string $field Field name
     * @param mixed $value Field value
     * @param array $rules Length rules
     */
    private function validateLength($field, $value, array $rules)
    {
        $length = is_string($value) ? strlen($value) : (is_array($value) ? count($value) : 0);

        if (isset($rules['min_length']) && $length < $rules['min_length']) {
            $this->addError($field, "Field '{$field}' must be at least {$rules['min_length']} characters");
        }

        if (isset($rules['max_length']) && $length > $rules['max_length']) {
            $this->addError($field, "Field '{$field}' must not exceed {$rules['max_length']} characters");
        }
    }

    /**
     * Validate numeric range
     * 
     * @param string $field Field name
     * @param mixed $value Field value
     * @param array $rules Range rules
     */
    private function validateRange($field, $value, array $rules)
    {
        if (!is_numeric($value)) {
            return;
        }

        $numValue = floatval($value);

        if (isset($rules['min']) && $numValue < $rules['min']) {
            $this->addError($field, "Field '{$field}' must be at least {$rules['min']}");
        }

        if (isset($rules['max']) && $numValue > $rules['max']) {
            $this->addError($field, "Field '{$field}' must not exceed {$rules['max']}");
        }
    }

    /**
     * Validate enum value
     * 
     * @param string $field Field name
     * @param mixed $value Field value
     * @param array $allowedValues Allowed values
     */
    private function validateEnum($field, $value, array $allowedValues)
    {
        if (!in_array($value, $allowedValues, true)) {
            $allowed = implode(', ', $allowedValues);
            $this->addError($field, "Field '{$field}' must be one of: {$allowed}");
        }
    }

    /**
     * Check eligibility for NEW category
     * 
     * @param array $customerData Customer data
     * @return array Eligibility result with status and reasons
     */
    public function checkNewEligibility(array $customerData)
    {
        $this->logger->info('Checking NEW category eligibility', [
            'customer_id' => $customerData['customer_id'] ?? 'unknown',
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        try {
            $criteria = $this->eligibilityCriteria[self::CATEGORY_NEW]['criteria'];
            $reasons = [];
            $eligible = true;

            // Check contract age
            if (isset($customerData['contract_date'])) {
                $contractAge = $this->calculateDateDifference($customerData['contract_date']);
                if ($contractAge > $criteria['contract_age_max_days']) {
                    $eligible = false;
                    $reasons[] = "Contract is too old ({$contractAge} days, max {$criteria['contract_age_max_days']} days)";
                }
            } else {
                $eligible = false;
                $reasons[] = "Contract date is missing";
            }

            // Check status
            if (!in_array($customerData['status'] ?? '', $criteria['required_status'])) {
                $eligible = false;
                $reasons[] = "Status must be active";
            }

            // Check purchase history
            if (($customerData['total_purchases'] ?? 0) > $criteria['max_purchases']) {
                $eligible = false;
                $reasons[] = "Customer has existing purchases";
            }

            // Check verification requirement
            if ($criteria['requires_verification'] && empty($customerData['email_verified'])) {
                $eligible = false;
                $reasons[] = "Email verification required";
            }

            $result = [
                'eligible' => $eligible,
                'category' => self::CATEGORY_NEW,
                'reasons' => $reasons,
                'checked_at' => date('Y-m-d H:i:s')
            ];

            $this->logger->info('NEW category eligibility check completed', $result);
            return $result;

        } catch (Exception $e) {
            return $this->handleEligibilityException($e, self::CATEGORY_NEW, $customerData);
        }
    }

    /**
     * Check eligibility for PASSIVE category
     * 
     * @param array $customerData Customer data
     * @return array Eligibility result with status and reasons
     */
    public function checkPassiveEligibility(array $customerData)
    {
        $this->logger->info('Checking PASSIVE category eligibility', [
            'customer_id' => $customerData['customer_id'] ?? 'unknown',
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        try {
            $criteria = $this->eligibilityCriteria[self::CATEGORY_PASSIVE]['criteria'];
            $reasons = [];
            $eligible = true;

            // Check inactivity period
            if (isset($customerData['last_activity_date'])) {
                $inactivityDays = $this->calculateDateDifference($customerData['last_activity_date']);
                
                if ($inactivityDays < $criteria['inactivity_min_days']) {
                    $eligible = false;
                    $reasons[] = "Customer is still active (inactive for {$inactivityDays} days, min {$criteria['inactivity_min_days']} days)";
                }
                
                if ($inactivityDays > $criteria['inactivity_max_days']) {
                    $eligible = false;
                    $reasons[] = "Customer inactive too long ({$inactivityDays} days, max {$criteria['inactivity_max_days']} days)";
                }
            } else {
                $eligible = false;
                $reasons[] = "Last activity date is missing";
            }

            // Check status
            if (!in_array($customerData['status'] ?? '', $criteria['required_status'])) {
                $eligible = false;
                $reasons[] = "Status must be inactive";
            }

            // Check previous purchases
            if (($customerData['total_purchases'] ?? 0) < $criteria['min_previous_purchases']) {
                $eligible = false;
                $reasons[] = "Customer must have at least {$criteria['min_previous_purchases']} previous purchase(s)";
            }

            $result = [
                'eligible' => $eligible,
                'category' => self::CATEGORY_PASSIVE,
                'reasons' => $reasons,
                'checked_at' => date('Y-m-d H:i:s')
            ];

            $this->logger->info('PASSIVE category eligibility check completed', $result);
            return $result;

        } catch (Exception $e) {
            return $this->handleEligibilityException($e, self::CATEGORY_PASSIVE, $customerData);
        }
    }

    /**
     * Check eligibility for DIAGNOSTIC category
     * 
     * @param array $customerData Customer data
     * @return array Eligibility result with status and reasons
     */
    public function checkDiagnosticEligibility(array $customerData)
    {
        $this->logger->info('Checking DIAGNOSTIC category eligibility', [
            'customer_id' => $customerData['customer_id'] ?? 'unknown',
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        try {
            $criteria = $this->eligibilityCriteria[self::CATEGORY_DIAGNOSTIC]['criteria'];
            $reasons = [];
            $eligible = true;

            // Check contract age
            if (isset($customerData['contract_date'])) {
                $contractAge = $this->calculateDateDifference($customerData['contract_date']);
                if ($contractAge < $criteria['contract_age_min_days']) {
                    $eligible = false;
                    $reasons[] = "Contract is too recent ({$contractAge} days, min {$criteria['contract_age_min_days']} days)";
                }
            } else {
                $eligible = false;
                $reasons[] = "Contract date is missing";
            }

            // Check status
            if (!in_array($customerData['status'] ?? '', $criteria['required_status'])) {
                $eligible = false;
                $reasons[] = "Status must be active or inactive";
            }

            // Check service request requirement
            if ($criteria['requires_service_request'] && empty($customerData['has_service_request'])) {
                $eligible = false;
                $reasons[] = "Service request is required";
            }

            // Check verification requirement
            if ($criteria['requires_verification'] && empty($customerData['email_verified'])) {
                $eligible = false;
                $reasons[] = "Email verification required";
            }

            $result = [
                'eligible' => $eligible,
                'category' => self::CATEGORY_DIAGNOSTIC,
                'reasons' => $reasons,
                'checked_at' => date('Y-m-d H:i:s')
            ];

            $this->logger->info('DIAGNOSTIC category eligibility check completed', $result);
            return $result;

        } catch (Exception $e) {
            return $this->handleEligibilityException($e, self::CATEGORY_DIAGNOSTIC, $customerData);
        }
    }

    /**
     * Check eligibility for LEAD category
     * 
     * @param array $customerData Customer data
     * @return array Eligibility result with status and reasons
     */
    public function checkLeadEligibility(array $customerData)
    {
        $this->logger->info('Checking LEAD category eligibility', [
            'customer_id' => $customerData['customer_id'] ?? 'unknown',
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        try {
            $criteria = $this->eligibilityCriteria[self::CATEGORY_LEAD]['criteria'];
            $reasons = [];
            $eligible = true;

            // Check status
            if (!in_array($customerData['status'] ?? '', $criteria['required_status'])) {
                $eligible = false;
                $reasons[] = "Status must be active";
            }

            // Check contract age for new leads
            if (isset($customerData['contract_date'])) {
                $contractAge = $this->calculateDateDifference($customerData['contract_date']);
                if ($contractAge > $criteria['max_contract_age_days']) {
                    $eligible = false;
                    $reasons[] = "Contract is too old for lead category ({$contractAge} days, max {$criteria['max_contract_age_days']} days)";
                }
            }

            // Check contact information requirement
            if ($criteria['requires_contact_info']) {
                if (empty($customerData['email']) && empty($customerData['phone'])) {
                    $eligible = false;
                    $reasons[] = "Valid contact information (email or phone) is required";
                }
            }

            $result = [
                'eligible' => $eligible,
                'category' => self::CATEGORY_LEAD,
                'reasons' => $reasons,
                'checked_at' => date('Y-m-d H:i:s')
            ];

            $this->logger->info('LEAD category eligibility check completed', $result);
            return $result;

        } catch (Exception $e) {
            return $this->handleEligibilityException($e, self::CATEGORY_LEAD, $customerData);
        }
    }

    /**
     * Check eligibility for all categories
     * 
     * @param array $customerData Customer data
     * @return array Eligibility results for all categories
     */
    public function checkAllEligibilities(array $customerData)
    {
        $this->logger->info('Checking eligibility for all categories', [
            'customer_id' => $customerData['customer_id'] ?? 'unknown',
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        return [
            self::CATEGORY_NEW => $this->checkNewEligibility($customerData),
            self::CATEGORY_PASSIVE => $this->checkPassiveEligibility($customerData),
            self::CATEGORY_DIAGNOSTIC => $this->checkDiagnosticEligibility($customerData),
            self::CATEGORY_LEAD => $this->checkLeadEligibility($customerData)
        ];
    }

    /**
     * Get eligible categories for customer
     * 
     * @param array $customerData Customer data
     * @return array List of eligible categories
     */
    public function getEligibleCategories(array $customerData)
    {
        $allEligibilities = $this->checkAllEligibilities($customerData);
        $eligibleCategories = [];

        foreach ($allEligibilities as $category => $result) {
            if ($result['eligible']) {
                $eligibleCategories[] = $category;
            }
        }

        $this->logger->info('Eligible categories determined', [
            'customer_id' => $customerData['customer_id'] ?? 'unknown',
            'eligible_categories' => $eligibleCategories,
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        return $eligibleCategories;
    }

    /**
     * Calculate date difference in days
     * 
     * @param string $date Date string
     * @param string|null $referenceDate Reference date (defaults to today)
     * @return int Days difference
     */
    private function calculateDateDifference($date, $referenceDate = null)
    {
        $referenceDate = $referenceDate ?? date('Y-m-d');
        
        $date1 = new DateTime($date);
        $date2 = new DateTime($referenceDate);
        
        $diff = $date2->diff($date1);
        return abs($diff->days);
    }

    /**
     * Check if a string is a valid date
     * 
     * @param string $date Date string
     * @param string $format Date format
     * @return bool True if valid date
     */
    private function isValidDate($date, $format = 'Y-m-d')
    {
        if (!is_string($date)) {
            return false;
        }

        $dateTime = DateTime::createFromFormat($format, $date);
        return $dateTime && $dateTime->format($format) === $date;
    }

    /**
     * Add validation error
     * 
     * @param string $field Field name
     * @param string $message Error message
     */
    private function addError($field, $message)
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        
        $this->errors[$field][] = $message;
        
        $this->logger->warning('Validation error added', [
            'field' => $field,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Get validation errors
     * 
     * @return array Validation errors
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Get formatted error messages
     * 
     * @return array Flat array of error messages
     */
    public function getErrorMessages()
    {
        $messages = [];
        
        foreach ($this->errors as $field => $fieldErrors) {
            foreach ($fieldErrors as $error) {
                $messages[] = $error;
            }
        }
        
        return $messages;
    }

    /**
     * Clear validation errors
     */
    public function clearErrors()
    {
        $this->errors = [];
        
        $this->logger->info('Validation errors cleared', [
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Check if validation has errors
     * 
     * @return bool True if there are errors
     */
    public function hasErrors()
    {
        return !empty($this->errors);
    }

    /**
     * Get eligibility criteria for a category
     * 
     * @param string $category Category name
     * @return array|null Criteria or null if category not found
     */
    public function getEligibilityCriteria($category)
    {
        return $this->eligibilityCriteria[$category] ?? null;
    }

    /**
     * Get all eligibility criteria
     * 
     * @return array All eligibility criteria
     */
    public function getAllEligibilityCriteria()
    {
        return $this->eligibilityCriteria;
    }

    /**
     * Validate and check eligibility in one call
     * 
     * @param array $customerData Customer data
     * @param string|null $category Specific category or null for all
     * @return array Validation and eligibility results
     */
    public function validateAndCheckEligibility(array $customerData, $category = null)
    {
        $this->logger->info('Starting validation and eligibility check', [
            'customer_id' => $customerData['customer_id'] ?? 'unknown',
            'category' => $category ?? 'all',
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        $validationResult = $this->validate($customerData);
        
        $result = [
            'validation' => [
                'passed' => $validationResult,
                'errors' => $this->getErrors()
            ],
            'eligibility' => []
        ];

        if ($validationResult) {
            if ($category) {
                $method = 'check' . ucfirst($category) . 'Eligibility';
                if (method_exists($this, $method)) {
                    $result['eligibility'][$category] = $this->$method($customerData);
                }
            } else {
                $result['eligibility'] = $this->checkAllEligibilities($customerData);
            }
        }

        $this->logger->info('Validation and eligibility check completed', [
            'validation_passed' => $validationResult,
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        return $result;
    }

    /**
     * Handle validation exception
     * 
     * @param Exception $e Exception object
     * @param string $method Method name where exception occurred
     * @param array $data Related data
     * @return bool Always returns false
     */
    private function handleValidationException(Exception $e, $method, array $data)
    {
        $this->logger->error('Validation exception occurred', [
            'method' => $method,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        $this->addError('system', 'Validation error: ' . $e->getMessage());
        return false;
    }

    /**
     * Handle eligibility check exception
     * 
     * @param Exception $e Exception object
     * @param string $category Category being checked
     * @param array $data Customer data
     * @return array Error result
     */
    private function handleEligibilityException(Exception $e, $category, array $data)
    {
        $this->logger->error('Eligibility check exception occurred', [
            'category' => $category,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'customer_id' => $data['customer_id'] ?? 'unknown',
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        return [
            'eligible' => false,
            'category' => $category,
            'reasons' => ['System error: ' . $e->getMessage()],
            'error' => true,
            'checked_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Export validation report
     * 
     * @param array $customerData Customer data
     * @return array Comprehensive validation report
     */
    public function exportValidationReport(array $customerData)
    {
        $this->logger->info('Generating validation report', [
            'customer_id' => $customerData['customer_id'] ?? 'unknown',
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        $validationResult = $this->validateAndCheckEligibility($customerData);

        $report = [
            'customer_id' => $customerData['customer_id'] ?? 'unknown',
            'generated_at' => date('Y-m-d H:i:s'),
            'specification' => 'Smart 26',
            'validation' => $validationResult['validation'],
            'eligibility' => $validationResult['eligibility'],
            'summary' => [
                'validation_passed' => $validationResult['validation']['passed'],
                'eligible_categories' => [],
                'total_errors' => count($this->getErrorMessages())
            ]
        ];

        foreach ($validationResult['eligibility'] as $category => $result) {
            if ($result['eligible']) {
                $report['summary']['eligible_categories'][] = $category;
            }
        }

        $this->logger->info('Validation report generated', [
            'customer_id' => $customerData['customer_id'] ?? 'unknown',
            'eligible_categories' => $report['summary']['eligible_categories'],
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        return $report;
    }

    /**
     * Get validator statistics
     * 
     * @return array Validator statistics
     */
    public function getStatistics()
    {
        return [
            'total_validation_rules' => count($this->validationRules),
            'total_categories' => count($this->eligibilityCriteria),
            'categories' => array_keys($this->eligibilityCriteria),
            'current_errors' => count($this->errors),
            'specification' => 'Smart 26',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}

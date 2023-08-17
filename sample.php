<?php
    class Conversion {
        private $and = "and";
        private $or = "or";
        private $allowedOpeningParanthesis = [ "{", "(", "["];
        private $allowedClosingParanthesis = [ "}", ")", "]"];
        private $operatorsAllowed = ["and", "or"];
        public  $seperator = "||__||";

        private $openingAndClosingParanthesisMap = [
            '{' => '}',
            '[' => ']',
            '(' => ')'
        ];
        private $openingAndClosingParanthesisReverseMap = [
            '}' => '{',
            ']' => '[',
            ')' => '('
        ];

        private $operatorFunction = [
            '='  => 'term',
            '!=' => 'notEqualTo',
            '>'  => 'greaterThan',
            '<'  => 'lessThan',
            '>=' => 'greaterThanEqualTo',
            '<=' => 'lessThanEqualTo',
            '~'  => 'IN',
            '!~' => 'notIn'
        ];

        private function IN($fieldName, $fieldValues) {
            $cleanedString = substr($fieldValues, 1, -1); // Remove any brackets
            $stringArray = explode(',', $cleanedString);
            return [
                "terms" => [
                    $fieldName => $stringArray
                ]
            ];
        }
        
        private function notIn($fieldName, $fieldValues) {
            $cleanedString = substr($fieldValues, 1, -1); // Remove any brackets
            $stringArray = explode(',', $cleanedString);
            return [
                "must_not" => [
                    "terms" => [
                        $fieldName => $stringArray
                    ]
                ]
            ];
        }

        private function notEqualTo($fieldName, $fieldValue) {
            return [
                "must_not" => [
                    "term" => [
                        $fieldName => $fieldValue
                    ]
                ]
            ];
        }

        private function greaterThan($fieldName, $fieldValue) {
            return [
                "range" => [
                    $fieldName => [
                        "gt" => $fieldValue
                    ]
                ]
            ];
        }

        private function greaterThanEqualTo($fieldName, $fieldValue) {
            return [
                "range" => [
                    $fieldName => [
                        "gte" => $fieldValue
                    ]
                ]
            ];
        }

        private function lessThanEqualTo($fieldName, $fieldValue) {
            return [
                "range" => [
                    $fieldName => [
                        "lte" => $fieldValue
                    ]
                ]
            ];
        }

        private function lessThan($fieldName, $fieldValue) {
            return [
                "range" => [
                    $fieldName => [
                        "lt" => $fieldValue
                    ]
                ]
            ];
        }

        private function customMatch($fieldName, $fieldValue) {
            return [
                "match" => [
                    $fieldName => $fieldValue
                ]
            ];
        }

        private function term($fieldName, $fieldValue) {
            if (strpos($fieldValue, "\"") === false) {
                return $this->customMatch($fieldName, $fieldValue);
            }
        
            $cleanedFieldValue = str_replace("\"", "", $fieldValue);
            return [
                "term" => [
                    $fieldName => $cleanedFieldValue
                ]
            ];
        }
        
        private function orOperation($oplist) {
            return [
                "bool" => [
                    "should" => $oplist
                ]
            ];
        }
        
        private function andOperation($oplist) {
            return [
                "bool" => [
                    "must" => $oplist
                ]
            ];
        }
        
        private function matchPhrase($fieldName, $fieldValue) {
            return [
                "match_phrase" => [
                    $fieldName => $fieldValue
                ]
            ];
        }

        private function verifyParanthesis($input) {
            $stack = array();
            foreach($input as $literal){
                if (in_array($literal, $this->allowedOpeningParanthesis)) {
                    array_push($stack, $literal);
                } else if (in_array($literal, $this->allowedClosingParanthesis)) {
                    if (count($stack) === 0) {
                        return false;
                    }
                    $openingBrace = array_pop($stack);
                    if (empty($this->openingAndClosingParanthesisMap[$openingBrace]) || $this->openingAndClosingParanthesisMap[$openingBrace] !== $literal) {
                        return false;
                    }
                }
            }
            return count($stack) === 0;
        }

        private function convertInfixToPostfix($input) {
            $stack = array();
            $postfixExp = array();
            foreach($input as $literal){
                if (in_array($literal, $this->allowedOpeningParanthesis)) {
                    array_push($stack, $literal);
                } elseif (in_array($literal, $this->allowedClosingParanthesis)) {
                    $openParanthesis = $this->openingAndClosingParanthesisReverseMap[$literal];
                    while (count($stack) != 0) {
                        $pop = array_pop($stack);
                        if ($pop == $openParanthesis) {
                            break;
                        }
                        array_push($postfixExp, $pop);
                    }
                } elseif (in_array($literal, $this->operatorsAllowed)) {
                    array_push($stack, $literal);
                } else {
                    array_push($postfixExp, $literal);
                }
            }
            while (count($stack) != 0) {
                array_push($postfixExp, array_pop($stack));
            }
            return $postfixExp;
        }

        private function constructESQuery($input) {
            $stack = array();
        
            // Regular expression to match any of the specified operators
            $operatorRegex = '/(!=|<=|>=|<|>|=|~)/';  
        
            foreach($input as $literal) {
                if (!in_array($literal, $this->operatorsAllowed)) {
                    $parts = preg_split($operatorRegex, $literal, -1, PREG_SPLIT_DELIM_CAPTURE);
                    if (count($parts) != 3) {
                        throw new Exception("Not a valid expression");
                    } else {
                        $operator = $parts[1];
                        if (!isset($this->operatorFunction[$operator])) {
                            throw new Exception("Not a valid operator");
                        }
                        array_push($stack, $this->getResult($operator,[$parts[0], $parts[2]]));
                    }
                } else {
                    $op1 = array_pop($stack);
                    $op2 = array_pop($stack);
                    if ($literal == "and") {
                        array_push($stack, $this->andOperation([$op1, $op2]));
                    } else {
                        array_push($stack, $this->orOperation([$op1, $op2]));
                    }
                }
            }
            return $stack[0];
            //echo json_encode($stack);
        }

        private function getResult($operator, $operands){
            switch($operator){
                case '=':
                    return $this->term($operands[0], $operands[1]);
                case '!=':
                    return $this->notEqualTo($operands[0], $operands[1]);
                case '>':
                    return $this->greaterThan($operands[0], $operands[1]);
                case '>=':
                    return $this->greaterThanEqualTo($operands[0], $operands[1]);
                case '<':
                    return $this->lessThan($operands[0], $operands[1]);
                case '<=':
                    return $this->lessThanEqualTo($operands[0], $operands[1]);
                case '~':
                    return $this->IN($operands[0], $operands[1]);
                case '!~':
                    return $this->notIn($operands[0], $operands[1]);
                default:
                    throw new Exception("No suitable function found for the operator $operator");
            }
        }
        
        public function processKeyValueBasedInput($str) {
            $input = explode($this->seperator, $str); // Replace $seperator with your separator string
            // First verify the parentheses allowed parentheses are { } ( ) []
            $paranthesisMatch = $this->verifyParanthesis($input);
            if (!$paranthesisMatch) {
                throw new Exception("ERROR: Parenthesis mismatched");
            }
        
            // Convert the input now to postfix expression
            $postfix = $this->convertInfixToPostfix($input);
            // Now we have the postfix expression, build the ESquery
            $esQuery = $this->constructESQuery($postfix);
            return $esQuery;
        }

        public function processValueBasedInput($str, $fieldNames){ 
            $input = explode($this->seperator, $str);
            // First verify the paranthesis allowed paranthesis are { } ( ) []
            $paranthesisMatch = $this->verifyParanthesis($input);
            if(!$paranthesisMatch){
                throw new Exception("ERROR: Parenthesis mismatched");
            }
        
            // Convert the input now to postfix expression
            $postfix = $this->convertInfixToPostfix($input);
            
            // Now we have the postfix expression, build the ESquery
            $esQuery = $this->constructESQueryForValueBasedInput($postfix, $fieldNames);
            return $esQuery;
        }

        private function constructESQueryForValueBasedInput($input, $fieldNames){
            $stack = [];
            
            foreach($input as $literal){
                if(!in_array($literal, $this->operatorsAllowed)){
                    $orListForFieldNames = array_map(function ($fieldName) use ($literal) {
                        return !(strpos($literal,"\"") === false) ? $this->term($fieldName, $literal) : $this->customMatch($fieldName, $literal);
                    }, $fieldNames);
                    array_push($stack, $this->orOperation($orListForFieldNames));
                }
                else{
                    $op1 = array_pop($stack);
                    $op2 = array_pop($stack);
                    if($literal == "and"){
                        array_push($stack, $this->andOperation([$op1, $op2]));
                    }
                    else{
                        array_push($stack,$this->orOperation([$op1, $op2]));
                    }
                }
            }
            return $stack[0];
            //console.log(JSON.stringify(stack));
        }

        
    }
    $conv = new Conversion();
    $str1 = "{".$conv->seperator."gender=\"male\"".$conv->seperator."and".$conv->seperator."(".$conv->seperator."(".$conv->seperator."education>=5".$conv->seperator."or".$conv->seperator."address=everything".$conv->seperator.")".$conv->seperator."or".$conv->seperator."(".$conv->seperator."exp<10".$conv->seperator."or".$conv->seperator."abcd~[kaka,baba,adithya]".$conv->seperator.")".$conv->seperator.")".$conv->seperator."}";
    $str = "{".$conv->seperator."\"a\"".$conv->seperator."and".$conv->seperator."(".$conv->seperator."(".$conv->seperator."b".$conv->seperator."or".$conv->seperator."c".$conv->seperator.")".$conv->seperator."or".$conv->seperator."(".$conv->seperator."d".$conv->seperator."or".$conv->seperator."e".$conv->seperator."or".$conv->seperator."f".$conv->seperator.")".$conv->seperator.")".$conv->seperator."}";
    try{
        echo json_encode($conv->processKeyValueBasedInput($str1));
        echo "\n";
        echo json_encode($conv->processValueBasedInput($str,["f1", "f2"]));
    }catch(Exception $e){
        echo $e->getMessage();
    }
        
    
    
    
    


?>
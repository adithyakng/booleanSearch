const and = "and";
const or = "or";
const allowedOpeningParanthesis = [ "{", "(", "["];
const allowedClosingParanthesis = [ "}", ")", "]"];
const operatorsAllowed = ["and", "or"];
const seperator = "||__||"

const openingAndClosingParanthesisMap = Object.freeze({
    '{' : '}',
    '[' : ']',
    '(' : ')'
})
const openingAndClosingParanthesisReverseMap = Object.freeze({
    '}' : '{',
    ']' : '[',
    ')' : '('
})

const operatorFunction = Object.freeze({
    '=' : term,
    "!=": notEqualTo,
    '>' : greaterThan,
    '<' : lessThan,
    '>=': greterThanEqualTo,
    '<=' : lessThanEqualTo,
    '~' : IN,
    '!~': notIn
})

function IN(fieldName, fieldValues){
    const cleanedString = fieldValues.slice(1, -1); // Remove any brackets
    const stringArray = cleanedString.split(',');
    return {
        "terms" : {
            [fieldName] : stringArray
        }
    };

}

function notIn(fieldName, fieldValues){
    const cleanedString = fieldValues.slice(1, -1); // Remove any brackets
    const stringArray = cleanedString.split(',');
    return {
        "must_not": {
            "terms" : {
                [fieldName] : stringArray
            }
        }
    };

}

function notEqualTo(fieldName, fieldValue){
    return {
        "must_not" : {
            "term" : {
              [fieldName] : fieldValue
            }
          }
    }
}

function greaterThan(fieldName, fieldValue){
    return {
        "range": {
            [fieldName]: {
                "gt": fieldValue,
            }
        }
    }
}

function greterThanEqualTo(fieldName, fieldValue){
    return {
        "range": {
            [fieldName]: {
                "gte": fieldValue,
            }
        }
    }
}

function lessThanEqualTo(fieldName, fieldValue){
    return {
        "range": {
            [fieldName]: {
                "lte": fieldValue,
            }
        }
    }
}


function lessThan(fieldName, fieldValue){
    return {
        "range": {
            [fieldName]: {
                "lt": fieldValue,
            }
        }
    }
}

function term(fieldName, fieldValue){
    if(!fieldValue.includes("\"")){
        return match(fieldName, fieldValue);
    }
    return {
        "term": {
            [fieldName]: fieldValue.replaceAll("\"", "")
        }
    }
}

function OR(oplist){
    return {
        "bool": {
            "should": [
                ...oplist
            ]
        }
    }
}

function AND(oplist){
    return {
        "bool": {
            "must": [
                ...oplist
            ]
        }
    }
}

function matchPhrase(fieldName, fieldValue){
    return {
        "match_phrase": {
            [fieldName] : fieldValue
        }
    };
}

function match(fieldName, fieldValue){
    return {
        "match": {
            [fieldName]: fieldValue
        }
    }
}

function processValueBasedInput(str, fieldNames){ 
    const input = str.split(seperator);
    // First verify the paranthesis allowed paranthesis are { } ( ) []
    const paranthesisMatch = verifyParanthesis(input);
    if(!paranthesisMatch){
        throw ("ERROR: Paranthesis mismatched");
    }
    //console.log(paranthesisMatch);

    // Convert the input now to postfix expression
    const postfix = convertInfixToPostfix(input);
    
    // Now we have the postfix expression, build the ESquery
    const esQuery = constructESQueryForValueBasedInput(postfix, fieldNames);
    return esQuery;
}

function processKeyValueBasedInput(str){
    const input = str.split(seperator);

    // First verify the paranthesis allowed paranthesis are { } ( ) []
    const paranthesisMatch = verifyParanthesis(input);
    if(!paranthesisMatch){
        throw ("ERROR: Paranthesis mismatched");
    }
    //console.log(paranthesisMatch);
    // Convert the input now to postfix expression
    const postfix = convertInfixToPostfix(input);

     // Now we have the postfix expression, build the ESquery
     const esQuery = constructESQuery(postfix);
     return esQuery;
}

function verifyParanthesis(input){

    const stack = [];
    for(let i=0; i<input.length; i++){
        if(allowedOpeningParanthesis.includes(input[i])){
            stack.push(input[i]);
        }
        else if(allowedClosingParanthesis.includes(input[i])){
            if(stack.length == 0){
                return 0;
            }
            const openingBrace = stack.pop();
            if(openingAndClosingParanthesisMap[openingBrace] != input[i]){
                return 0;
            }
        }
    }
    return stack.length == 0 ? 1 : 0;
}

function convertInfixToPostfix(input){
    const stack = [];
    const postfixExp = [];
    for(let i=0; i<input.length; i++){
        const literal = input[i];
        if(allowedOpeningParanthesis.includes(literal)){
            stack.push(literal);
        }
        else if(allowedClosingParanthesis.includes(literal)){
            const openParanthesis = openingAndClosingParanthesisReverseMap[literal];
            while(stack.length != 0){
                const pop = stack.pop();
                if(pop == openParanthesis){
                    break;
                }
                postfixExp.push(pop);
            }
        }
        else if(operatorsAllowed.includes(literal)){
            stack.push(literal);
        }
        else{
            postfixExp.push(literal);
        }
    }
    while(stack.length !=0){
        postfixExp.push(stack.pop());
    }
    return postfixExp;

}

function constructESQueryForValueBasedInput(input, fieldNames){
    const stack = [];
    
    for(let i=0; i<input.length; i++){
        const literal = input[i];
        if(!operatorsAllowed.includes(literal)){
            const orListForFieldNames = fieldNames.map((fieldName) => literal.includes("\"") ? term(fieldName, literal) : match(fieldName, literal));
            stack.push(OR(orListForFieldNames));
        }
        else{
            const op1 = stack.pop();
            const op2 = stack.pop();
            if(literal == "and"){
                stack.push(AND([op1, op2]));
            }
            else{
                stack.push(OR([op1, op2]));
            }
        }
    }
    return stack[0];
    //console.log(JSON.stringify(stack));
}

function constructESQuery(input){
    const stack = [];

    // Regular expression to match any of the specified operators
    const operatorRegex = /(!=|<=|>=|<|>|=|~)/;  
    

    for(let i=0; i<input.length; i++){
        const literal = input[i];
        if(!operatorsAllowed.includes(literal)){
            const parts = literal.split(operatorRegex);
            if(parts.length != 3){
                throw "No a valid expression";
            }
            else{
                const operator = parts[1];
                if(!operatorFunction[operator]){
                    throw "No a valid operator";
                }
                stack.push(operatorFunction[operator](parts[0], parts[2]));
            }
        }
        else{
            const op1 = stack.pop();
            const op2 = stack.pop();
            if(literal == "and"){
                stack.push(AND([op1, op2]));
            }
            else{
                stack.push(OR([op1, op2]));
            }
        }
    }
    return stack[0];
    //console.log(JSON.stringify(stack));
}

const str = `{${seperator}"a"${seperator}and${seperator}(${seperator}(${seperator}b${seperator}or${seperator}c${seperator})${seperator}or${seperator}(${seperator}d${seperator}or${seperator}e${seperator}or${seperator}f${seperator})${seperator})${seperator}}`;
const str1 = `{${seperator}gender="male"${seperator}and${seperator}(${seperator}(${seperator}education>=5${seperator}or${seperator}address=everything${seperator})${seperator}or${seperator}(${seperator}exp<10${seperator}or${seperator}abcd~[kaka,baba,adithya]${seperator})${seperator})${seperator}}`;
console.log(JSON.stringify(processValueBasedInput(str,["f1","f2"])));
// console.log(str.replaceAll(seperator, " "));
// console.log(JSON.stringify(processKeyValueBasedInput(str1)));

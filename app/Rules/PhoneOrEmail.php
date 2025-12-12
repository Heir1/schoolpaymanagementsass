<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PhoneOrEmail implements ValidationRule
{
    /**
     * Valider si la valeur est un email ou un numéro de téléphone valide
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Vérifier si c'est un email valide
        $isEmail = filter_var($value, FILTER_VALIDATE_EMAIL);
        
        // Vérifier si c'est un numéro de téléphone valide (format international simplifié)
        $isPhone = preg_match('/^(\+?\d{1,4}[\s\-]?)?(\(?\d{1,4}\)?[\s\-]?)?[\d\s\-]{6,14}$/', $value);
        
        if (!$isEmail && !$isPhone) {
            $fail('Le champ :attribute doit être un email valide ou un numéro de téléphone.');
        }
    }
}
<?php

namespace App\Support;

class NigerianStates
{
    /**
     * The 36 states plus the Federal Capital Territory.
     *
     * @return array<string,string>
     */
    public static function options(): array
    {
        $states = [
            'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue',
            'Borno', 'Cross River', 'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu',
            'Gombe', 'Imo', 'Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi', 'Kogi',
            'Kwara', 'Lagos', 'Nasarawa', 'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo',
            'Plateau', 'Rivers', 'Sokoto', 'Taraba', 'Yobe', 'Zamfara',
            'Federal Capital Territory',
        ];

        return array_combine($states, $states);
    }

    /** @return array<int,string> */
    public static function names(): array
    {
        return array_keys(self::options());
    }
}

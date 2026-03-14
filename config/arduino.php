<?php

/**
 * Arduino CLI Configuration
 * 
 * Set the path to the arduino-cli binary and default board settings.
 */

return [
    // Path to the arduino-cli executable
    'cli_path' => getenv('ARDUINO_CLI_PATH') ?: 'arduino-cli',

    // Default FQBN (Fully Qualified Board Name)
    'default_fqbn' => 'arduino:avr:uno',

    // Additional arduino-cli global flags
    'global_flags' => '--no-color',

    // Common board FQBNs for quick reference
    'known_boards' => [
        'uno'       => 'arduino:avr:uno',
        'nano'      => 'arduino:avr:nano',
        'mega'      => 'arduino:avr:mega',
        'leonardo'  => 'arduino:avr:leonardo',
        'micro'     => 'arduino:avr:micro',
        'esp32'     => 'esp32:esp32:esp32',
        'esp8266'   => 'esp8266:esp8266:nodemcuv2',
        'due'       => 'arduino:sam:arduino_due_x',
        'mkr1000'   => 'arduino:samd:mkr1000',
        'nano33iot' => 'arduino:samd:nano_33_iot',
        'nano33ble' => 'arduino:mbed_nano:nano33ble',
        'rp2040'    => 'arduino:mbed_rp2040:pico',
    ],

    // Additional board manager URLs
    'additional_urls' => [
        'https://raw.githubusercontent.com/espressif/arduino-esp32/gh-pages/package_esp32_index.json',
        'https://arduino.esp8266.com/stable/package_esp8266com_index.json',
    ],
];

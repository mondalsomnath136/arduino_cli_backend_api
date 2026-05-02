<?php

namespace App\MCP\Prompts;

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Types\PromptMessage;
use Mcp\Types\TextContent;
use Mcp\Types\Role;

/**
 * MCP Arduino Prompts
 *
 * Pre-defined prompt templates that guide AI agents through common
 * Arduino development workflows.
 */
class ArduinoPrompts
{
    /**
     * Provide a structured prompt to compile an Arduino sketch.
     *
     * @param  string  $board       Target board short-name or FQBN.
     * @param  string  $sketch      Optional description or partial code of the sketch.
     * @param  string  $objective   What the sketch should do (e.g., "blink LED on pin 13").
     * @return PromptMessage[]  Prompt messages for the AI agent.
     */
    #[McpPrompt(
        name: 'compile_sketch',
        description: 'Guided workflow to write and compile an Arduino sketch. Provide the target board and what the sketch should do, and this prompt will guide you through writing correct, compilable code.'
    )]
    public function compileSketch(
        string $board     = 'uno',
        string $sketch    = '',
        string $objective = ''
    ): array {
        $boardInfo  = empty($board) ? 'Arduino Uno (uno)' : $board;
        $objSection = empty($objective) ? 'Describe what your sketch should do.' : "Objective: {$objective}";
        $codeSection = empty($sketch) ? '' : "\n\nExisting sketch:\n```cpp\n{$sketch}\n```";

        return [
            new PromptMessage(
                role: Role::User,
                content: new TextContent(
                    text: <<<PROMPT
I need to compile an Arduino sketch for: {$boardInfo}

{$objSection}{$codeSection}

Please:
1. Write or complete the Arduino sketch code (setup() and loop() functions required)
2. Make sure all required libraries are noted (I can install them with arduino_library_install)
3. Use the arduino_verify tool first to check for syntax errors
4. If verify passes, use arduino_compile to compile and get the binary
5. Report the job_id and binary download URLs if compilation succeeds

Known board short-names available: uno, nano, mega, leonardo, micro, esp32, esp8266, due, mkr1000, nano33iot, nano33ble, rp2040
PROMPT
                )
            ),
        ];
    }

    /**
     * Provide a structured prompt to troubleshoot a compilation error.
     *
     * @param  string  $error_output  The compiler error output to troubleshoot.
     * @param  string  $code          The sketch code that caused the error.
     * @param  string  $board         The target board.
     * @return PromptMessage[]  Prompt messages guiding error resolution.
     */
    #[McpPrompt(
        name: 'troubleshoot_error',
        description: 'Troubleshoot Arduino compilation errors. Provide the error output and your sketch code to get a diagnosis and corrected code that compiles successfully.'
    )]
    public function troubleshootError(
        string $error_output = '',
        string $code         = '',
        string $board        = 'uno'
    ): array {
        $errorSection = empty($error_output)
            ? 'Paste the compiler error output here.'
            : "Compiler error output:\n```\n{$error_output}\n```";

        $codeSection = empty($code)
            ? 'Paste your Arduino sketch code here.'
            : "Arduino sketch:\n```cpp\n{$code}\n```";

        return [
            new PromptMessage(
                role: Role::User,
                content: new TextContent(
                    text: <<<PROMPT
I have a compilation error on board: {$board}

{$errorSection}

{$codeSection}

Please:
1. Analyze the error message and identify the root cause
2. Check if any libraries need to be installed (use arduino_library_search and arduino_library_install)
3. Fix the code — explain each change you make
4. Use arduino_verify to confirm the fix compiles without errors
5. If verify passes, use arduino_compile to produce the final binary
PROMPT
                )
            ),
        ];
    }

    /**
     * Provide a structured prompt to set up a new board type.
     *
     * @param  string  $board_name  The board or platform to set up (e.g., "ESP32", "Arduino Nano 33 IoT").
     * @return PromptMessage[]  Step-by-step board setup guidance.
     */
    #[McpPrompt(
        name: 'board_setup',
        description: 'Step-by-step guide to set up a new board type on this Arduino CLI server. Installs the required platform, updates the index, and verifies setup with a test sketch.'
    )]
    public function boardSetup(string $board_name = 'ESP32'): array
    {
        return [
            new PromptMessage(
                role: Role::User,
                content: new TextContent(
                    text: <<<PROMPT
I want to set up the Arduino board/platform: {$board_name}

Please follow these steps in order:
1. Use arduino_board_update_index to refresh the board package index
2. Use arduino_board_search to find the correct platform identifier for "{$board_name}"
3. Use arduino_board_install to install the platform
4. Use arduino_board_list to confirm it appears in the installed platforms list
5. Write a simple "Blink" test sketch for this board
6. Use arduino_verify to confirm the sketch compiles for this board type
7. Report the FQBN and short-name alias to use for future compilations

Report any errors encountered and how to resolve them.
PROMPT
                )
            ),
        ];
    }
}

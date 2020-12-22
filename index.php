<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Materials</title>

    <!-- Custom CSS -->
    <link rel="stylesheet" href="theme.css">
</head>

<body>

    <h1>Zaagindeling profielen</h1>
    <?php

        $coreConfigLines = explode(
            "\n",
            file_get_contents("core.conf")
        );
        $coreConfig = [];
        foreach ($coreConfigLines as $coreConfigLine) {
            $keyValuePair = explode(":", $coreConfigLine);
            $coreConfig[$keyValuePair[0]] = (int)$keyValuePair[1];
        }

        $profileConfigLines = explode(
            "\n",
            file_get_contents("profile_lengths.conf")
        );
        $profileConfig = [];
        foreach ($profileConfigLines as $profileConfigLine) {
            $keyValuePair = explode(":", $profileConfigLine);
            $profileConfig[$keyValuePair[0]] = (int)$keyValuePair[1];
        }

        $gripLength = $coreConfig["grip_length"] ?? 200;
        $sawThickness = $coreConfig["saw_thickness"] ?? 2;
        $defaultProfileLength = $coreConfig["default_profile_length"] ?? 6000;

        $formIsSubmitted = ($_POST["submit"] ?? false) == true;
        $inputString = $formIsSubmitted
            ? str_replace(" ", "", trim(htmlspecialchars($_POST["dataDump"])))
            : "";
    ?>

    <div class="panels">
        <div class="panel">
            <h2>Input (Pos; Profiel; Lengte; Aantal)</h2>
            <form method="POST">
                <textarea required name="dataDump" rows="12" cols="50"><?php echo $inputString; ?></textarea>
                <br>
                <input type="submit" value="Berekenen" name="submit">
            </form>
        </div>
        <div class="panel">
            <h2>Configuratie</h2>
                <h3>Core configuratie</h3>
                <table>
                    <tr><th>Instelling</th><th>Waarde (mm)</th></tr>
                    <tr><td>Standaard profiel lengte</td><td><?php echo $defaultProfileLength ?></td></tr>
                    <tr><td>Uitsparing vastklemmen</td><td><?php echo $gripLength ?></td></tr>
                    <tr><td>Zaagdikte</td><td><?php echo $sawThickness ?></td></tr>
                </table>
                <h3>Profiellengte configuratie</h3>
                <table>
                    <tr><th>Profiel</th><th>Lengte (mm)</th></tr>
                    <?php
                        foreach ($profileConfig as $profile => $length) {
                            echo "<tr><td>" . $profile . "</td><td>" . $length . "</td></tr>";
                        }
                    ?>
                </table>
            <?php

                if ($formIsSubmitted) {
                    echo "<h3>Totalen per profiel</h3>";
                    $inputLines = explode("\n", $inputString);
                    $inputArray = array_map(
                        function ($inputLine) use ($sawThickness) {
                            $values = explode(";", $inputLine);
                            return [
                                "pos" => $values[0],
                                "material" => $values[1],
                                "length" => ((int)$values[2]+$sawThickness),
                                "count" => (int)$values[3],
                            ];
                        },
                        $inputLines
                    );

                    $materialLengthCountMapping = [];
                    foreach ($inputArray as $values) {
                        $material = $values["material"];
                        
                        if (!array_key_exists($material, $materialLengthCountMapping)) {
                            $materialLengthCountMapping[$material] = [$values];
                            continue;
                        }
                        
                        $materialLengthCountMapping[$material][] = $values;
                    }

                    $ultimateLookupTable = [];
                    foreach ($materialLengthCountMapping as $material => $valuesArray) {
                        $lengthCountMapping = [];
                        foreach ($valuesArray as $values) {
                            $length = $values["length"];
                            $count = $values["count"];
                            
                            if (!array_key_exists($length, $lengthCountMapping)) {
                                $lengthCountMapping[$length] = $count;
                                continue;
                            }

                            $lengthCountMapping[$length] += $count;
                        }

                        $materialLength = array_key_exists($material, $profileConfig)
                            ? $profileConfig[$material]
                            : $defaultProfileLength;

                        krsort($lengthCountMapping);
                        $ultimateLookupTable[$material] = [
                            "length" => $materialLength,
                            "mapping" => $lengthCountMapping,
                        ];
                    }

                    foreach ($ultimateLookupTable as $material => $lengthAndMapping) {
                        echo "<h5>" . $material . " (" . $lengthAndMapping["length"] . " mm)</h5>";
                        echo
        "<table>
          <tr>
            <th>Length (mm)</th>
            <th>Count</th>
          </tr>
        ";
                        $total = 0;
                        foreach ($lengthAndMapping["mapping"] as $length => $count) {
                            echo "<tr>";
                            echo "<td>" . ($length-$sawThickness) . "</td>";
                            echo "<td>" . $count . "</td>";
                            echo "</tr>";
                            $total += $count;
                        }

                        echo "<tr>";
                        echo "<td><small>TOTAAL</small></td>";
                        echo "<td><small>" . $total . "</small></td>";
                        echo "</tr>";
                        echo "</table>";
                    }
                }
            ?>
        </div>
        <div class="panel">
            <h2>Zaagindelingen per profiel</h2>
            <?php
                try {
                    if ($formIsSubmitted) {
                        $setsPerMaterial = [];
                        $notInSet = $ultimateLookupTable;
                        foreach ($ultimateLookupTable as $material => $values) {
                            $materialLength = $values["length"];
                            $materialMapping = $values["mapping"];
                            $materialMappingNotInSet = $values["mapping"];

                            $sets = [];
                            do {
                                $handledGripLength = false;
                                $restLength = $materialLength;
                                $theOneSet = [
                                    "parts" => [],
                                ];
                                do {
                                    foreach ($materialMappingNotInSet as $partLength => $partCount) {
                                        if ($partCount < 1) {
                                            continue;
                                        }
                                        if ($partLength > $materialLength) {
                                            throw new Exception(
    "Profiel " . $material . "(" .  $materialLength . "mm) is te klein! Onderdeel met lengte " . $partLength . "mm kan hier niet uit gezaagd worden."
                                            );

                                        }
                                        if (!$handledGripLength) {
                                            $optionalGripLength = 0;
                                            if ($partLength <= $gripLength) {
                                                $restLength -= $gripLength;
                                                $optionalGripLength = $gripLength;
                                            }
                                            $handledGripLength = true;
                                        }
                                        if ($restLength < $partLength) {
                                            continue;
                                        }
                                        $maxTimesPartCanFit = (int)($restLength / $partLength);
                                        $maxTimesPartFits = min($maxTimesPartCanFit, $partCount);

                                        $theOneSet["parts"][$partLength] = $maxTimesPartFits;
                                        $restLength -= $partLength*$maxTimesPartFits;
                                    }
                                    $restLength += $optionalGripLength;
                                    break;
                                } while ($restLength > 0);

                                $record = 999999999999;
                                foreach ($theOneSet["parts"] as $partLength => $partTimesInSet) {
                                    $maxPossibleRepsForSet = (int)($materialMappingNotInSet[$partLength] / $partTimesInSet);
                                    if ($maxPossibleRepsForSet < $record) {
                                        $record = $maxPossibleRepsForSet;
                                    }
                                }
                                $repeatSet = $record;

                                foreach ($materialMappingNotInSet as $partLength => $partCount) {
                                    if (!array_key_exists($partLength, $theOneSet["parts"])) {
                                        continue;
                                    }
                                    $materialMappingNotInSet[$partLength] = $partCount - ($repeatSet*$theOneSet["parts"][$partLength]);
                                    $notInSet[$material]["mapping"] = $materialMappingNotInSet;
                                }

                                $theOneSet["restLength"] = $restLength;
                                $theOneSet["repetitions"] = $repeatSet;

                                $sets[] = $theOneSet;

                                $absoluteNrOfPartsLeft = 0;
                                foreach ($materialMappingNotInSet as $partLength => $partCount) {
                                    $absoluteNrOfPartsLeft += $partCount;
                                }

                            } while ($absoluteNrOfPartsLeft > 0);

                            $setsPerMaterial[$material] = [
                                "materialLength" => $materialLength,
                                "sets" => $sets,
                            ];
                        }

                        $setId = 0;
                        foreach ($setsPerMaterial as $material => $values) {
                            echo "<h5>" . $material . " (" . $values["materialLength"] . " mm)</h5>";
                            echo
            "<table>
              <tr>
                <th>Setnummer</th>
                <th>Zaagindeling</th>
                <th>Aantal herhalingen</th>
                <th>Lengte overblijfsel</th>
              </tr>
            ";
                            foreach ($values["sets"] as $set) {
                                $setId++;
                                echo "<tr>";
                                echo "<td>" . $setId . "</td>";
                                echo "<td>";
                                $parts = [];
                                foreach ($set["parts"] as $partLength => $partCount) {
                                    $parts[] = "<small>" . $partCount . "x</small>" . "<b>" . ($partLength-$sawThickness) . "</b>";
                                }
                                echo implode(" ", $parts);
                                echo "</td>";
                                echo "<td>" . $set["repetitions"] . "</td>";
                                echo "<td>" . $set["restLength"] . "</td>";
                                echo "</tr>";
                            }


                            echo "</table>";
                        }
                    }
                } catch (Exception $exception) {
                    exception($exception);
                }
            ?>
        </div>
    </div>
</body>
</html>

<?php
    function exception(Exception $exception): void
    {
        echo "<pre class=\"exception\">";
        echo "<b>Message: \"</b>" . $exception->getMessage() . "<b>\"</b><br>";
        echo "<b>File:</b> " . $exception->getFile() . "<br>";
        echo "<b>Line:</b> " . $exception->getLine() . "<br>";
        echo "</pre>";
    }

    function pre(array $array): void
    {
        echo "<pre>";
        print_r($array);
        echo "</pre>";
    }

    function json(array $array): void
    {
        echo "<pre>";
        print_r(json_encode($array, JSON_PRETTY_PRINT));
        echo "</pre>";
    }

?>

<?php

class BCC_Login_Comparer {
    static function match($conditions, $user) {
        if ( $user == null || $conditions == null) {
            return false;
        }

        $access = true;

        foreach ( $conditions as $index => $condition )
        {
            $match = false;

            if (array_key_exists($condition['key'], $user)) {
                switch ( $condition['compare'] )
                {
                    case '<':
                        $match = ( $user[$condition['key']] < $condition['value'] );
                        break;
        
                    case '<=':
                        $match = ( $user[$condition['key']] <= $condition['value'] );
                        break;

                    case '==':
                        $match = ( $user[$condition['key']] == $condition['value'] );
                        break;
        
                    case '!=':
                        $match = ( $user[$condition['key']] != $condition['value'] );
                        break;

                    case '>=':
                        $match = ( $user[$condition['key']] >= $condition['value'] );
                        break;

                    case '>':
                        $match = ( $user[$condition['key']] > $condition['value'] );
                        break;

                    case 'IN':
                        $match = ( in_array($condition['value'], $user[$condition['key']]) );
                        break;

                    case 'CONTAINS':
                        foreach ($user[$condition['key']] as $item) {
                            if ( $item[$condition['contains_key']] == $condition['value'] ) {
                                $match = true;
                                break;
                            }
                        }
                        break;
        
                    default:
                        // Handle unsupported operators
                        $match = false;
                        break;
                }
            }

            if ( $match == false ) 
            {
                $access = false;
                return;
            }
        }

        return $access;
    }
}
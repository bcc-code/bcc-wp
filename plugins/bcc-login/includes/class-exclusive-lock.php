<?php

class ExclusiveLock
{
    protected $key   = null;
    protected $file  = null;
    protected $own   = FALSE;

    function __construct( $key ) 
    {
        error_log("construct lock");
        $this->key = $key;

        $this->file = fopen("$key.lockfile", 'w+');
    }

    function __destruct() 
    {
        if( $this->own == TRUE )
            $this->unlock( );
    }

    function lock( ) 
    {
        if( !flock($this->file, LOCK_EX | LOCK_NB)) 
        {
            $key = $this->key;
            return FALSE;
        }

        $this->own = TRUE;
        return TRUE;
    }

    function unlock( ) 
    {
        $key = $this->key;
        if( $this->own == TRUE ) 
        {
            if( !flock($this->file, LOCK_UN) )
            {
                error_log("ExclusiveLock::lock FAILED to release lock [$key]");
                return FALSE;
            }
            ftruncate($this->file, 0);
            fwrite( $this->file, "Unlocked\n");
            fflush( $this->file );
            $this->own = FALSE;
        }
        else
        {
            error_log("ExclusiveLock::unlock called on [$key] but its not acquired by caller");
        }

        return TRUE;
    }
};

?>
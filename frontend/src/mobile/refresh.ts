import { useEffect } from 'react'

/** Screens listen for this so the header's refresh button reloads whatever is on screen. */
export const REFRESH_EVENT = 'cms:mobile-refresh'

export function useReloadOnRefresh(reload: () => void) {
  useEffect(() => {
    const handler = () => reload()
    window.addEventListener(REFRESH_EVENT, handler)
    return () => window.removeEventListener(REFRESH_EVENT, handler)
  }, [reload])
}

( function( ui, $, wp ) {
	var data = {
		processActive: ui.processActive,
		processInfo: {},
		hasMoreLogs: true
	};

	ui.template = false;

	ui.init = function() {
 		if ( ! wp.heartbeat ) {
 			console.error( ui.l10n.missingHeartbeat );
 			return;
 		}

 		if ( ! wp.ajax || ! wp.template ) {
 			console.error( ui.l10n.missingUtil );
 			return;
 		}

 		if ( ! $( ui.selectors.progress ).length ) {
 			console.error( ui.l10n.invalidProgressSelector );
 			return;
 		}

 		if ( ! $( ui.selectors.dispatchButton ).length ) {
 			console.error( ui.l10n.invalidDispatchButtonSelector );
 			return;
 		}

 		$( document ).on( 'heartbeat-send', function( e, heartbeatData ) {
 			heartbeatData.requestBackgroundProcessInfo = ui.processIdentifier;

 			if ( data.processInfo.logs && data.processInfo.logs.length ) {
 				heartbeatData.backgroundProcessInfoLatestLogTimestamp = data.processInfo.logs[0].timestamp;
 			}
 		});

 		$( document ).on( 'heartbeat-tick', function( e, response ) {
 			if ( ! response.backgroundProcessInfo ) {
 				data.processActive = false;
 				data.processInfo = {};
 				data.hasMoreLogs = true;
 			} else {
 				data.processActive = true;
 				if ( data.processInfo.logs && data.processInfo.logs.length ) {
 					response.backgroundProcessInfo.logs = response.backgroundProcessInfo.logs.concat( data.processInfo.logs );
 				}
 				data.processInfo = response.backgroundProcessInfo;
 				data.processInfo.percentage = parseInt( ( data.processInfo.progress / data.processInfo.total ) * 100, 10 );
 				if ( 25 > data.processInfo.logs.length ) {
 					data.hasMoreLogs = false;
 				}
 			}

 			ui.refreshContent();
 		});

 		$( document ).on( 'click', ui.selectors.dispatchButton, function( e ) {
 			wp.ajax.post( 'dispatch_background_process_' + ui.processIdentifier, {
 				nonce: ui.processNonce
 			}).done( function( response ) {
 				data.processActive = true;
 				ui.refreshButtonState();
 			}).fail( function( message ) {
 				console.error( message );
 			});
 		});

 		$( document ).on( 'click', '#logs-more', function( e ) {
 			wp.ajax.post( 'get_background_process_' + ui.processIdentifier + '_logs', {
 				nonce: ui.processNonce,
 				beforeTimestamp: data.processInfo.logs.length ? data.processInfo.logs[ data.processInfo.logs.length - 1 ].timestamp : false
 			}).done( function( response ) {
 				if ( 25 > response.length ) {
 					data.hasMoreLogs = false;
 				}
 				data.processInfo.logs = data.processInfo.logs.concat( response );

 				ui.refreshContent();
 			}).fail( function( message ) {
 				console.error( message );
 			});
 		});
	};

	ui.refreshButtonState = function() {
		if ( data.processActive ) {
			$( ui.selectors.dispatchButton ).prop( 'disabled', true );
		} else {
			$( ui.selectors.dispatchButton ).prop( 'disabled', false );
		}
	};

	ui.refreshContent = function() {
		ui.refreshButtonState();

		if ( ! ui.template ) {
			ui.template = wp.template( 'background-process-info' );
		}

		$( ui.selectors.progress ).html( ui.template( data ) )
	};
}( wpBackgroundProcessingUI, jQuery, wp ) );

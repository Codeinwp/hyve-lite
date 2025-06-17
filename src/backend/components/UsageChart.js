/**
 * External dependencies.
 */
import {
	Chart,
	BarController,
	CategoryScale,
	LinearScale,
	BarElement,
	Tooltip,
	Legend,
} from 'chart.js';

/**
 * WordPress dependencies.
 */
import { useState, useEffect, useRef, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { SelectControl } from '@wordpress/components';

Chart.register(
	BarController,
	CategoryScale,
	LinearScale,
	BarElement,
	Tooltip,
	Legend
);

const UsageChart = ( {
	id,
	legendLabel,
	data,
	labels,
	datasetBackgroundColor,
	datasetBorderColor,
} ) => {
	const chartRef = useRef( null );
	const [ chartInstance, setChartInstance ] = useState( null );

	// Effect for creating and destroying the chart instance
	useEffect( () => {
		if ( ! chartRef.current ) {
			return;
		}

		const ctx = chartRef.current.getContext( '2d' );
		const newChart = new Chart( ctx, {
			type: 'bar',
			data: {
				labels: [],
				datasets: [
					{
						label: legendLabel,
						data: [],
						backgroundColor: datasetBackgroundColor,
						borderColor: datasetBorderColor,
						borderWidth: 1,
					},
				],
			},
			options: {
				responsive: true,
				scales: {
					x: {
						ticks: {
							maxTicksLimit: 15,
						},
					},
					y: {
						beginAtZero: true,
					},
				},
			},
		} );
		setChartInstance( newChart );

		return () => {
			if ( newChart ) {
				newChart.destroy();
			}
			setChartInstance( null );
		};
	}, [ legendLabel, datasetBackgroundColor, datasetBorderColor ] );

	// Effect for updating chart data when dateRange or chart prop changes
	useEffect( () => {
		if ( chartInstance && labels ) {
			chartInstance.data.labels = labels;
			chartInstance.data.datasets[ 0 ].data = data;

			if (
				legendLabel &&
				chartInstance.data.datasets[ 0 ].label !== legendLabel
			) {
				chartInstance.data.datasets[ 0 ].label = legendLabel;
			}

			chartInstance.update();
		} else if ( chartInstance ) {
			chartInstance.data.labels = [];
			chartInstance.data.datasets[ 0 ].data = [];
			if ( legendLabel ) {
				chartInstance.data.datasets[ 0 ].label = legendLabel;
			}
			chartInstance.update();
		}
	}, [ chartInstance, legendLabel, labels, data ] );

	return (
		<div>
			<canvas id={ id } ref={ chartRef }></canvas>
		</div>
	);
};

export const UsageCharts = ( { chart } ) => {
	const [ dateRange, setDateRange ] = useState( 30 );

	const filteredLabels = useMemo( () => {
		return chart.labels.slice( -dateRange );
	}, [ chart.labels, dateRange ] );

	const filteredMessageData = useMemo( () => {
		return chart.data.messages.slice( -dateRange );
	}, [ chart.data.messages, dateRange ] );

	const filteredSessionsData = useMemo( () => {
		return chart.data.sessions.slice( -dateRange );
	}, [ chart.data.sessions, dateRange ] );

	return (
		<div className="flex flex-col gap-1">
			<div className="flex grow justify-end">
				<SelectControl
					label={ __( 'Show data for', 'hyve-lite' ) }
					options={ [
						{
							value: 7,
							label: __( 'Last 7 days', 'hyve-lite' ),
						},
						{
							value: 14,
							label: __( 'Last 14 days', 'hyve-lite' ),
						},
						{
							value: 30,
							label: __( 'Last 30 days', 'hyve-lite' ),
						},
						{
							value: 90,
							label: __( 'Last 90 days', 'hyve-lite' ),
						},
					] }
					value={ dateRange }
					onChange={ ( value ) => setDateRange( value ) }
					labelPosition="side"
				/>
			</div>
			<UsageChart
				id="messages-chart"
				legendLabel={ chart.legend.messagesLabel }
				labels={ filteredLabels }
				data={ filteredMessageData }
				datasetBorderColor={ 'rgba(54, 162, 235, 0.6)' }
				datasetBackgroundColor={ 'rgba(54, 162, 235, 1)' }
			/>
			<UsageChart
				id="sessions-chart"
				legendLabel={ chart.legend.sessionsLabel }
				labels={ filteredLabels }
				data={ filteredSessionsData }
				datasetBorderColor={ 'rgba(153, 102, 255, 0.6)' }
				datasetBackgroundColor={ 'rgba(153, 102, 255, 1)' }
			/>
		</div>
	);
};

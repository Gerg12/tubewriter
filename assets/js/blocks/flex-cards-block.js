const { registerBlockType } = wp.blocks;
const {
	RichText,
	InspectorControls,
	PanelColorSettings,
} = wp.editor;
const {
	TextControl,
	Button,
} = wp.components;

// Flex Cards Block
registerBlockType("custom-block-plugin/flex-cards-block", {
	title: "Flex Cards Block 2",
	icon: "grid-view",
	category: "common",
	attributes: {
		flexCards: {
			type: "array",
			default: [
				{ title: "", link: "", images: [] },
				{ title: "", link: "", images: [] },
				{ title: "", link: "", images: [] },
				{ title: "", link: "", images: [] },
			],
		},
		blocksPerRow: {
			type: "string",
			default: "2",
		},
		blockTitle: {
			type: "string",
			default: "",
		},
		blockText: {
			type: "string",
			default: "",
		},
		blockLink: {
			type: "string",
			default: "",
		},
	},
	edit: (props) => {
		const {
			attributes: {
				flexCards,
				backgroundColor,
				blockTitle,
				blockText,
				blockLink,
			},
			setAttributes,
		} = props;

		// Handler for title and link change in each card
		const onCardChange = (newTitle, newLink, index) => {
			const updatedCards = [...flexCards];
			updatedCards[index] = {
				...updatedCards[index],
				title: newTitle,
				link: newLink,
			};
			setAttributes({ flexCards: updatedCards });
		};

		// Handler for block title change
		const onBlockTitleChange = (newTitle) => {
			setAttributes({ blockTitle: newTitle });
		};

		// Handler for block text change
		const onBlockTextChange = (newText) => {
			setAttributes({ blockText: newText });
		};

		// Handler for block link change
		const onBlockLinkChange = (newLink) => {
			setAttributes({ blockLink: newLink });
		};

		// Handler for background color change
		const onBackgroundColorChange = (newColor) => {
			setAttributes({ backgroundColor: newColor });
		};

		return (
			<>
				<InspectorControls>
					{/* Color control for selecting background color */}
					<PanelColorSettings
						title="Background Color"
						initialOpen={true}
						colorSettings={[
							{
								value: backgroundColor,
								onChange: onBackgroundColorChange,
								label: "Background Color",
							},
						]}
					/>
				</InspectorControls>

				{/* Displaying the block */}
				<div
					className={`flex-cards__wrapper admin-edit`}
					style={{ backgroundColor: backgroundColor }}
				>
					<div className="flex-cards__inner">
						<div className="flex-cards__content">
							<div className="flex-cards__grid">
								{flexCards.map((card, index) => (
									<div key={index} className="flex-cards__item">
										<div className="flex-cards__item-inner">
											<Button 
												onClick={() => {
													const updatedCards = [...flexCards];
													if (!updatedCards[index].images) {
														updatedCards[index].images = [];
													}
													updatedCards[index].images.push({ url: '', id: '' });
													setAttributes({ flexCards: updatedCards });
												}}
												className="components-button is-secondary"
											>
												Add Image
											</Button>

											{(card.images || []).map((image, imageIndex) => (
												<div key={imageIndex} className="flex-cards__image-container">
													<MediaUpload
														onSelect={(media) => {
															const updatedCards = [...flexCards];
															updatedCards[index].images[imageIndex] = {
																url: media.url,
																id: media.id
															};
															setAttributes({ flexCards: updatedCards });
														}}
														allowedTypes={['image']}
														value={image.id}
														render={({ open }) => (
															<div className="flex-cards__image-wrapper">
																{image.url ? (
																	<>
																		<img
																			src={image.url}
																			alt=""
																			className="flex-cards__image"
																			onClick={open}
																		/>
																		<Button 
																			onClick={() => {
																				const updatedCards = [...flexCards];
																				updatedCards[index].images.splice(imageIndex, 1);
																				setAttributes({ flexCards: updatedCards });
																			}}
																			className="components-button is-link is-destructive"
																		>
																			Remove
																		</Button>
																	</>
																) : (
																	<Button 
																		onClick={open}
																		className="flex-cards__image-button"
																	>
																		Select Image
																	</Button>
																)}
															</div>
														)}
													/>
												</div>
											))}

											<RichText
												className="flex-cards__title"
												tagName="h2"
												value={card.title}
												onChange={(newTitle) =>
													onCardChange(newTitle, card.link, index)
												}
												placeholder="Enter title"
											/>
											<TextControl
												className="flex-cards__link"
												value={card.link}
												onChange={(newLink) =>
													onCardChange(card.title, newLink, index)
												}
												placeholder="Enter link"
											/>
											
										</div>
									</div>
								))}
							</div>
						</div>
					</div>
				</div>
			</>
		);
	},
	save: (props) => {
		const {
			attributes: {
				flexCards,
				blockTitle,
				blockText,
				blockLink,
				backgroundColor,
			},
		} = props;

		return (
			<div
				className={`flex-cards__wrapper`}
				style={{ backgroundColor: backgroundColor }}
			>
				<div className="flex-cards__inner">
					<div className="flex-cards__content">
						<div className="flex-cards__grid">
							{flexCards.map((card, index) => (
								<a key={index} href={card.link} className="flex-cards__item">
									<div className="flex-cards__item-inner">
										{(card.images || []).map((image, imageIndex) => (
											image.url && (
												<img 
													key={imageIndex}
													className={`flex-cards__image ${imageIndex === 0 ? 'active' : ''}`}
													src={image.url} 
													alt={card.title} 
													data-index={imageIndex}
												/>
											)
										))}
										<h2 className="flex-cards__title">{card.title}</h2>
									</div>
								</a>
							))}
						</div>
					</div>
				</div>
			</div>
		);
	},
});

document.addEventListener('DOMContentLoaded', function() {
	const cards = document.querySelectorAll('.flex-cards__item-inner');
	
	cards.forEach(card => {
		const images = card.querySelectorAll('.flex-cards__image');
		if (images.length > 1) {
			let currentIndex = 0;
			
			setInterval(() => {
				images[currentIndex].classList.remove('active');
				currentIndex = (currentIndex + 1) % images.length;
				images[currentIndex].classList.add('active');
			}, 3000); // Change image every 3 seconds
		}
	});
});